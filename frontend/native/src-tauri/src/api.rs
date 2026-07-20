use crate::config;
use serde::{Deserialize, Serialize};
use std::collections::HashMap;

#[derive(Debug, Deserialize)]
pub struct ApiRequest {
    pub path: String,
    pub method: String,
    pub headers: HashMap<String, String>,
    pub body: Option<String>,
}

#[derive(Debug, Serialize)]
pub struct ApiResponse {
    pub status: u16,
    pub body: String,
}

/// The webview supplies a path, never a host. Anything that looks like it is trying to
/// become an absolute URL is refused, so a compromised page cannot point the shell — and
/// the device token it carries — at another server.
pub fn validate_path(path: &str) -> Result<(), String> {
    if !path.starts_with('/') || path.contains("://") {
        return Err("Invalid API path.".to_string());
    }
    Ok(())
}

#[tauri::command]
pub async fn api_request(app: tauri::AppHandle, req: ApiRequest) -> Result<ApiResponse, String> {
    validate_path(&req.path)?;

    let base = config::load(&app)
        .server_url
        .ok_or_else(|| "No server configured.".to_string())?;

    let method = reqwest::Method::from_bytes(req.method.as_bytes()).map_err(|e| e.to_string())?;
    let mut builder = reqwest::Client::new().request(method, format!("{base}/api/v1{}", req.path));

    for (name, value) in req.headers {
        builder = builder.header(name, value);
    }
    if let Some(body) = req.body {
        builder = builder.body(body);
    }

    // Transport failures return Err, which the SPA's transport shim turns into the same
    // `network_unreachable` ApiError the browser produces — so every offline screen the
    // register already has keeps working unchanged.
    let response = builder.send().await.map_err(|e| e.to_string())?;
    let status = response.status().as_u16();
    let body = response.text().await.map_err(|e| e.to_string())?;

    Ok(ApiResponse { status, body })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn accepts_an_ordinary_api_path() {
        assert!(validate_path("/orders/123/receipt").is_ok());
    }

    #[test]
    fn rejects_a_path_that_is_really_an_absolute_url() {
        assert!(validate_path("https://evil.example.com/steal").is_err());
    }

    #[test]
    fn rejects_a_scheme_smuggled_mid_path() {
        assert!(validate_path("/orders/../../https://evil.example.com").is_err());
    }

    #[test]
    fn rejects_a_relative_path() {
        assert!(validate_path("orders").is_err());
    }
}
