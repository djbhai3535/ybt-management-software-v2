# YBT Management Software — Native Windows Build & Prerequisites Guide

**Document Version:** 1.0.0  
**Phase:** Phase 4.2 Native Build  
**Target Platform:** Windows 10 / Windows 11 (x64)  
**Binary Output:** Standalone MSI / NSIS Installer (`YBT Management Software.exe`)  

---

## 1. System Prerequisites & Hardware Requirements

| Prerequisite Component | Status on This Machine | Required Version / Workload | Download / Installation Source |
| :--- | :---: | :--- | :--- |
| **Node.js & npm** | **Installed** | Node.js v24.22.0, npm 11.19.1 | https://nodejs.org/ |
| **Microsoft Edge WebView2** | **Installed** | Runtime v156.0.4314.50 | Pre-installed on Windows 10/11 |
| **Rust & Cargo Toolchain** | **Not Installed** | Rust 1.78+ (`stable-x86_64-pc-windows-msvc`) | `https://win.rustup.rs/x86_64` |
| **Visual Studio C++ Build Tools** | **Not Installed** | MSVC v143 + Windows 10/11 SDK | `https://aka.ms/vs/17/release/vs_BuildTools.exe` |
| **Tauri CLI** | **Available via npm** | `@tauri-apps/cli` v2.0+ | `npm install -D @tauri-apps/cli` |

---

## 2. Official Installation Instructions (System-Wide)

Because installing C++ Build Tools requires approximately 4.5 GB to 6 GB of disk space and administrative privileges, user approval is required before running system-wide installers.

### Step 1: Install Visual Studio 2022 C++ Build Tools
Open an Administrator PowerShell prompt and execute:
```powershell
winget install --id Microsoft.VisualStudio.2022.BuildTools --override "--passive --wait --add Microsoft.VisualStudio.Workload.VCTools --includeRecommended"
```
*Components Included:*
- MSVC v143 C++ x64/x86 compiler toolset
- Windows 10/11 SDK
- C++ CMake tools

### Step 2: Install Rust Toolchain
Execute:
```powershell
winget install --id Rustlang.Rustup
# Or download rustup-init.exe from https://win.rustup.rs/x86_64
rustup-init.exe -y --default-toolchain stable --default-host x86_64-pc-windows-msvc
```
Restart the terminal session to reload environment variables (`$env:PATH`).

### Step 3: Compile Native Windows Executable
Navigate to the desktop directory:
```powershell
cd C:\CRM-Software\desktop
npm install
npx tauri build
```

---

## 3. Build Outputs & Locations

Upon successful compilation by Cargo and Tauri:
- **Standalone Executable:**
  `desktop/src-tauri/target/release/ybt-management-software.exe`
- **Windows MSI Installer:**
  `desktop/src-tauri/target/release/bundle/msi/YBT Management Software_1.0.0_x64_en-US.msi`
- **Windows NSIS Installer (Setup EXE):**
  `desktop/src-tauri/target/release/bundle/nsis/YBT Management Software_1.0.0_x64-setup.exe`

---

## 4. Upgrade & Data Safety Guarantee
The MSI/NSIS installer is configured with a persistent `GUID` product code:
- Updating the software updates binaries in `%PROGRAMFILES%\YBT Management Software\`.
- All tenant SQLite databases and local sessions reside in `%LOCALAPPDATA%\YBTManagement\storage\tenants\`.
- Application updates **never delete or overwrite tenant databases**.
