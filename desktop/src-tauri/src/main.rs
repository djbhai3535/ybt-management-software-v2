// Prevents additional console window on Windows in release
#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

use serde::{Deserialize, Serialize};
use std::net::{TcpListener, TcpStream};
use std::path::{Path, PathBuf};
use std::process::{Child, Command};
use std::sync::{Arc, Mutex};
use std::time::Duration;

#[cfg(windows)]
use std::os::windows::process::CommandExt;

#[cfg(windows)]
const CREATE_NO_WINDOW: u32 = 0x08000000;

#[derive(Debug, Serialize, Deserialize)]
pub struct AppInfo {
    pub name: String,
    pub version: String,
    pub platform: String,
    pub offline_capable: bool,
    pub server_port: u16,
    pub server_url: String,
}

#[allow(dead_code)]
struct AppState {
    port: u16,
    server_url: String,
    php_child: Arc<Mutex<Option<Child>>>,
}

#[tauri::command]
fn get_app_info(state: tauri::State<AppState>) -> AppInfo {
    AppInfo {
        name: "YBT Management Software".into(),
        version: "1.0.0".into(),
        platform: "windows".into(),
        offline_capable: true,
        server_port: state.port,
        server_url: state.server_url.clone(),
    }
}

#[tauri::command]
fn get_server_url(state: tauri::State<AppState>) -> String {
    state.server_url.clone()
}

#[tauri::command]
fn check_network_status(state: tauri::State<AppState>) -> bool {
    let addr = format!("127.0.0.1:{}", state.port);
    TcpStream::connect_timeout(
        &addr.parse().unwrap(),
        Duration::from_millis(500),
    ).is_ok()
}

fn find_available_port() -> u16 {
    TcpListener::bind("127.0.0.1:0")
        .and_then(|listener| listener.local_addr())
        .map(|addr| addr.port())
        .unwrap_or(8088)
}

fn resolve_paths() -> (PathBuf, PathBuf, Option<PathBuf>) {
    let current_exe = std::env::current_exe().unwrap_or_default();
    let exe_dir = current_exe.parent().map(|p| p.to_path_buf()).unwrap_or_else(|| PathBuf::from("."));

    // Candidate locations for php.exe
    let php_candidates = [
        exe_dir.join("php/php.exe"),
        exe_dir.join("resources/php/php.exe"),
        exe_dir.join("resources/desktop/php/php.exe"),
        exe_dir.join("desktop/php/php.exe"),
        exe_dir.join("../php/php.exe"),
        exe_dir.join("../desktop/php/php.exe"),
        exe_dir.join("../../desktop/php/php.exe"),
        exe_dir.join("../../../desktop/php/php.exe"),
        PathBuf::from(r"C:\CRM-Software\desktop\php\php.exe"),
    ];

    let mut resolved_php = PathBuf::from(r"C:\CRM-Software\desktop\php\php.exe");
    for cand in &php_candidates {
        if cand.exists() {
            resolved_php = cand.canonicalize().unwrap_or_else(|_| cand.clone());
            break;
        }
    }

    // Candidate locations for project root (where dashboard.php resides)
    let root_candidates = [
        exe_dir.clone(),
        exe_dir.join("resources"),
        exe_dir.join("../"),
        exe_dir.join("../../"),
        exe_dir.join("../../../"),
        PathBuf::from(r"C:\CRM-Software"),
    ];

    let mut resolved_root = PathBuf::from(r"C:\CRM-Software");
    for cand in &root_candidates {
        if cand.join("dashboard.php").exists() {
            resolved_root = cand.canonicalize().unwrap_or_else(|_| cand.clone());
            break;
        }
    }

    // If dashboard.php is found in resources subfolder
    if !resolved_root.join("dashboard.php").exists() {
        let res_cand = exe_dir.join("resources");
        if res_cand.exists() {
            if let Ok(entries) = std::fs::read_dir(&res_cand) {
                for entry in entries.flatten() {
                    let path = entry.path();
                    if path.is_dir() && path.join("dashboard.php").exists() {
                        resolved_root = path;
                        break;
                    }
                }
            }
        }
    }

    let php_ini = resolved_php.parent().map(|p| p.join("php.ini")).filter(|p| p.exists());

    (resolved_php, resolved_root, php_ini)
}

fn spawn_php_process(php_bin: &Path, root_dir: &Path, php_ini: Option<&Path>, port: u16) -> Option<Child> {
    let mut cmd = Command::new(php_bin);

    if let Some(ini) = php_ini {
        cmd.arg("-c").arg(ini);
    }

    cmd.arg("-S").arg(format!("127.0.0.1:{}", port));
    cmd.arg("-t").arg(root_dir);

    // CRITICAL: Set environment variables for PHP so APP_URL always matches the dynamic loopback port!
    cmd.env("APP_URL", format!("http://127.0.0.1:{}", port));
    cmd.env("APP_PORT", port.to_string());
    cmd.env("APP_ENV", "desktop");
    cmd.current_dir(root_dir);

    #[cfg(windows)]
    cmd.creation_flags(CREATE_NO_WINDOW);

    match cmd.spawn() {
        Ok(child) => {
            println!("Portable PHP started on port {} (PID: {})", port, child.id());
            Some(child)
        }
        Err(e) => {
            eprintln!("Warning: Could not spawn bundled PHP runtime: {}", e);
            None
        }
    }
}

fn wait_for_server(port: u16, max_attempts: u32) -> bool {
    let target = format!("127.0.0.1:{}", port);
    for _ in 0..max_attempts {
        if TcpStream::connect(&target).is_ok() {
            return true;
        }
        std::thread::sleep(Duration::from_millis(100));
    }
    false
}

fn main() {
    let (php_bin, root_dir, php_ini) = resolve_paths();
    let port = find_available_port();
    let server_url = format!("http://127.0.0.1:{}/dashboard.php", port);

    println!("Initializing YBT Management Software Native Desktop Shell...");
    println!("Web Root: {:?}", root_dir);
    println!("Portable PHP: {:?}", php_bin);
    println!("Selected Port: {}", port);

    let child = spawn_php_process(&php_bin, &root_dir, php_ini.as_deref(), port);
    if child.is_some() {
        let ready = wait_for_server(port, 40);
        if ready {
            println!("Local PHP micro-engine verified operational at {}", server_url);
        } else {
            eprintln!("Warning: Server port not responding within timeout.");
        }
    }

    let shared_child = Arc::new(Mutex::new(child));
    let cleanup_child = Arc::clone(&shared_child);
    let app_exit_child = Arc::clone(&shared_child);

    tauri::Builder::default()
        .manage(AppState {
            port,
            server_url,
            php_child: shared_child,
        })
        .invoke_handler(tauri::generate_handler![
            get_app_info,
            get_server_url,
            check_network_status
        ])
        .on_window_event(move |_window, event| {
            if let tauri::WindowEvent::Destroyed = event {
                if let Ok(mut lock) = cleanup_child.lock() {
                    if let Some(mut proc) = lock.take() {
                        let _ = proc.kill();
                        println!("Portable PHP process terminated cleanly on window destroy.");
                    }
                }
            }
        })
        .build(tauri::generate_context!())
        .expect("error while building YBT Management Software desktop shell")
        .run(move |_app_handle, event| {
            if let tauri::RunEvent::Exit = event {
                if let Ok(mut lock) = app_exit_child.lock() {
                    if let Some(mut proc) = lock.take() {
                        let _ = proc.kill();
                        println!("Portable PHP process terminated cleanly on application exit.");
                    }
                }
            }
        });
}
