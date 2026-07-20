pub mod driver;
pub mod escpos;
pub mod mock;

use driver::Printer;
use tauri::Manager;

/// Resolves the configured driver. Only "mock" exists today; a real driver is selected
/// here by config once one is written.
fn printer(app: &tauri::AppHandle) -> Result<Box<dyn Printer>, String> {
    let dir = app
        .path()
        .app_data_dir()
        .map_err(|e| e.to_string())?
        .join("print-jobs");

    Ok(Box::new(mock::MockPrinter::new(dir)))
}

#[tauri::command]
pub fn print_receipt(
    app: tauri::AppHandle,
    receipt: escpos::Receipt,
    currency: String,
) -> Result<(), String> {
    printer(&app)?.write(&escpos::encode(&receipt, &currency))
}

/// No authorization argument, deliberately. A token passed from JS to Rust would be
/// theatre: whatever could forge this call could forge the token too. Authority lives
/// where it can be audited — the SPA asks the server first, and the server writes the
/// audit row whether or not a drawer physically opens. See docs/05-rbac.md.
#[tauri::command]
pub fn open_drawer(app: tauri::AppHandle) -> Result<(), String> {
    printer(&app)?.write(&escpos::DRAWER_KICK)
}
