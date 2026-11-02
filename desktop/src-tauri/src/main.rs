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

/// Strips Windows verbatim prefix (`\\?\`) so PHP and C-runtime can open files cleanly
fn clean_path(path: &Path) -> PathBuf {
    let s = path.to_string_lossy();
    if let Some(stripped) = s.strip_prefix(r"\\?\") {
        PathBuf::from(stripped)
    } else {
        path.to_path_buf()
    }
}

fn resolve_paths() -> Result<(PathBuf, PathBuf, Option<PathBuf>, PathBuf), String> {
    let current_exe = std::env::current_exe().unwrap_or_default();
    let exe_dir = clean_path(&current_exe.parent().map(|p| p.to_path_buf()).unwrap_or_else(|| PathBuf::from(".")));

    // 1. Candidate locations for php.exe
    let php_candidates = [
        exe_dir.join("php").join("php.exe"),
        exe_dir.join("resources").join("php").join("php.exe"),
        exe_dir.join("resources").join("desktop").join("php").join("php.exe"),
        exe_dir.join("desktop").join("php").join("php.exe"),
        exe_dir.join("..").join("php").join("php.exe"),
        exe_dir.join("..").join("desktop").join("php").join("php.exe"),
        exe_dir.join("..").join("..").join("desktop").join("php").join("php.exe"),
        exe_dir.join("..").join("..").join("..").join("desktop").join("php").join("php.exe"),
        PathBuf::from(r"C:\CRM-Software\desktop\php\php.exe"),
    ];

    let mut resolved_php = None;
    for cand in &php_candidates {
        if cand.exists() {
            resolved_php = Some(clean_path(cand));
            break;
        }
    }
    let resolved_php = resolved_php.ok_or_else(|| {
        format!("Bundled PHP runtime (php.exe) not found. Checked: {:?}", php_candidates)
    })?;

    // 2. Candidate locations for project root (where dashboard.php resides)
    let root_candidates = [
        exe_dir.clone(),
        exe_dir.join("resources"),
        exe_dir.join(".."),
        exe_dir.join("..").join(".."),
        exe_dir.join("..").join("..").join(".."),
        PathBuf::from(r"C:\CRM-Software"),
    ];

    let mut resolved_root = None;

    // Developer override: prioritize explicit source root if specified via environment
    if let Ok(env_root) = std::env::var("YBT_SOURCE_ROOT") {
        let p = clean_path(&PathBuf::from(env_root));
        if p.join("dashboard.php").exists() {
            resolved_root = Some(p);
        }
    }

    if resolved_root.is_none() {
        for cand in &root_candidates {
            if cand.join("dashboard.php").exists() {
                resolved_root = Some(clean_path(cand));
                break;
            }
        }
    }

    // If dashboard.php is found in resources subfolder
    let resolved_root = match resolved_root {
        Some(r) => r,
        None => {
            let res_cand = exe_dir.join("resources");
            let mut found = None;
            if res_cand.exists() {
                if let Ok(entries) = std::fs::read_dir(&res_cand) {
                    for entry in entries.flatten() {
                        let path = clean_path(&entry.path());
                        if path.is_dir() && path.join("dashboard.php").exists() {
                            found = Some(path);
                            break;
                        }
                    }
                }
            }
            found.ok_or_else(|| {
                format!("Application root containing dashboard.php not found. Checked: {:?}", root_candidates)
            })?
        }
    };

    // 3. php.ini location
    let php_ini = resolved_php.parent().map(|p| clean_path(&p.join("php.ini"))).filter(|p| p.exists());

    // 4. AppData writable database storage directory
    let app_data = std::env::var("APPDATA")
        .map(|v| clean_path(&PathBuf::from(v)))
        .unwrap_or_else(|_| exe_dir.join("storage"));
    let sqlite_db_dir = app_data.join("YBT Management Software").join("storage").join("desktop");
    let _ = std::fs::create_dir_all(&sqlite_db_dir);
    let sqlite_db_path = sqlite_db_dir.join("ybt_local.sqlite");

    Ok((resolved_php, resolved_root, php_ini, sqlite_db_path))
}

fn spawn_php_process(
    php_bin: &Path,
    root_dir: &Path,
    php_ini: Option<&Path>,
    sqlite_db: &Path,
    port: u16
) -> Option<Child> {
    let mut cmd = Command::new(php_bin);

    if let Some(ini) = php_ini {
        cmd.arg("-c").arg(ini);
    }

    cmd.arg("-S").arg(format!("127.0.0.1:{}", port));
    cmd.arg("-t").arg(root_dir);

    // CRITICAL: Set environment variables for PHP so APP_URL always matches the dynamic loopback port
    cmd.env("APP_URL", format!("http://127.0.0.1:{}", port));
    cmd.env("APP_PORT", port.to_string());
    cmd.env("APP_ENV", "desktop");
    cmd.env("YBT_SQLITE_PATH", sqlite_db);
    if let Some(parent) = sqlite_db.parent().and_then(|p| p.parent()) {
        cmd.env("YBT_STORAGE_PATH", parent);
    }
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
    let (php_bin, root_dir, php_ini, sqlite_db_path) = match resolve_paths() {
        Ok(paths) => paths,
        Err(err) => {
            eprintln!("FATAL STARTUP ERROR: {}", err);
            std::process::exit(1);
        }
    };

    let port = find_available_port();
    let server_url = format!("http://127.0.0.1:{}/dashboard.php", port);
    let dashboard_path = root_dir.join("dashboard.php");

    println!("=======================================================");
    println!(" YBT Management Software — Runtime Supervisor Diagnostics");
    println!("=======================================================");
    println!("1. PHP Executable:      {}", php_bin.display());
    println!("2. PHP Configuration:   {}", php_ini.as_ref().map(|p| p.display().to_string()).unwrap_or_else(|| "None".to_string()));
    println!("3. Application Resource: {}", root_dir.display());
    println!("4. Document Root:       {}", root_dir.display());
    println!("5. Dashboard Absolute:  {}", dashboard_path.display());
    println!("6. AppData Database:    {}", sqlite_db_path.display());
    println!("7. Allocated Port:      {}", port);
    println!("8. Dynamic Loopback URL: {}", server_url);
    println!("=======================================================");

    if !dashboard_path.exists() {
        eprintln!("CRITICAL ERROR: dashboard.php is missing at {}", dashboard_path.display());
        std::process::exit(1);
    }

    let child = spawn_php_process(&php_bin, &root_dir, php_ini.as_deref(), &sqlite_db_path, port);
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
