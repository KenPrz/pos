use serde::{Deserialize, Serialize};
use std::fs;
use std::path::PathBuf;
use tauri::Manager;

/// Terminal configuration, persisted in Tauri's app-config dir.
///
/// The server address lives HERE, in Rust, not in the webview. The webview passes a
/// path and never a host, so a compromised page cannot redirect a device token to
/// another server.
#[derive(Debug, Default, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct Config {
    #[serde(default)]
    pub server_url: Option<String>,
}

/// Pure so it can be tested without an app handle: trims, strips trailing slashes, and
/// insists on a scheme. Plain http is allowed — a till on a shop LAN is a real case.
pub fn normalize_server_url(input: &str) -> Result<String, String> {
    let trimmed = input.trim().trim_end_matches('/');

    if trimmed.is_empty() {
        return Err("Server address is required.".to_string());
    }
    if !trimmed.starts_with("http://") && !trimmed.starts_with("https://") {
        return Err("Server address must start with http:// or https://".to_string());
    }

    Ok(trimmed.to_string())
}

fn config_path(app: &tauri::AppHandle) -> Result<PathBuf, String> {
    let dir = app.path().app_config_dir().map_err(|e| e.to_string())?;
    fs::create_dir_all(&dir).map_err(|e| e.to_string())?;
    Ok(dir.join("config.json"))
}

/// A missing or unreadable config is an unconfigured terminal, not an error: first run
/// is the common case, and the setup screen is the recovery path either way.
pub fn load(app: &tauri::AppHandle) -> Config {
    let Ok(path) = config_path(app) else {
        return Config::default();
    };
    let Ok(raw) = fs::read_to_string(path) else {
        return Config::default();
    };
    serde_json::from_str(&raw).unwrap_or_default()
}

pub fn save(app: &tauri::AppHandle, config: &Config) -> Result<(), String> {
    let path = config_path(app)?;
    let raw = serde_json::to_string_pretty(config).map_err(|e| e.to_string())?;
    fs::write(path, raw).map_err(|e| e.to_string())
}

#[tauri::command]
pub fn get_config(app: tauri::AppHandle) -> Config {
    load(&app)
}

#[tauri::command]
pub fn set_server_url(app: tauri::AppHandle, url: String) -> Result<(), String> {
    let normalized = normalize_server_url(&url)?;
    let mut config = load(&app);
    config.server_url = Some(normalized);
    save(&app, &config)
}

/// Probes a CANDIDATE address before it is saved. This cannot be a webview `fetch` (that
/// would be cross-origin from `tauri://localhost` and die on CORS) and it cannot be
/// `api_request` (which reads the saved URL, and nothing is saved yet). Returns a plain
/// bool: the setup screen only needs "can I reach a POS server here?".
#[tauri::command]
pub async fn check_server(url: String) -> bool {
    let Ok(normalized) = normalize_server_url(&url) else {
        return false;
    };

    let Ok(response) = reqwest::Client::new()
        .get(format!("{normalized}/api/v1/health"))
        .send()
        .await
    else {
        return false;
    };

    // /health answers 503 when the database is down. That is still a POS server at this
    // address, which is all the setup screen is asking.
    response.status().is_success() || response.status().as_u16() == 503
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn normalizes_a_url_by_trimming_whitespace_and_trailing_slashes() {
        assert_eq!(
            normalize_server_url("  https://pos.example.com/  ").unwrap(),
            "https://pos.example.com"
        );
    }

    #[test]
    fn rejects_an_empty_address() {
        assert!(normalize_server_url("   ").is_err());
    }

    #[test]
    fn rejects_an_address_without_a_scheme() {
        assert!(normalize_server_url("pos.example.com").is_err());
    }

    #[test]
    fn accepts_plain_http_for_a_lan_till() {
        assert_eq!(
            normalize_server_url("http://192.168.1.10:8000").unwrap(),
            "http://192.168.1.10:8000"
        );
    }

    #[test]
    fn config_round_trips_through_json() {
        let cfg = Config {
            server_url: Some("https://pos.example.com".into()),
        };
        let raw = serde_json::to_string(&cfg).unwrap();
        assert_eq!(
            serde_json::from_str::<Config>(&raw).unwrap().server_url,
            cfg.server_url
        );
    }

    #[test]
    fn a_missing_file_deserializes_to_an_empty_config() {
        assert_eq!(
            serde_json::from_str::<Config>("{}").unwrap().server_url,
            None
        );
    }
}
