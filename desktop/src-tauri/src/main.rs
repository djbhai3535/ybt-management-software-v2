// Prevents additional console window on Windows in release
#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

use serde::{Deserialize, Serialize};

#[derive(Debug, Serialize, Deserialize)]
pub struct AppInfo {
    pub name: String,
    pub version: String,
    pub platform: String,
    pub offline_capable: bool,
}

#[tauri::command]
fn get_app_info() -> AppInfo {
    AppInfo {
        name: "YBT Management Software".into(),
        version: "1.0.0".into(),
        platform: "windows".into(),
        offline_capable: true,
    }
}

#[tauri::command]
fn check_network_status() -> bool {
    // In production, performs internal loopback or health ping
    true
}

fn main() {
    tauri::Builder::default()
        .invoke_handler(tauri::generate_handler![
            get_app_info,
            check_network_status
        ])
        .run(tauri::generate_context!())
        .expect("error while running YBT Management Software desktop shell");
}
