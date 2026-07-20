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

/// The webview supplies a path, never a host. The host is fixed by `api_request`'s own
/// `format!("{base}/api/v1{}", req.path)`, so this function's job is not to stop the
/// authority from being escaped (it can't be) — it's to stop the path from being used to
/// reach routes outside `/api/v1` on the trusted server. Rejected: paths that don't start
/// with `/`; paths containing `://` (an embedded scheme); paths starting with `//`
/// (protocol-relative); paths containing a backslash (WHATWG treats it as a slash); and
/// paths containing a `..` path segment (dot-segment collapsing could strip the `/api/v1`
/// prefix). A `..` occurring only as part of a longer segment (e.g. a filename) is fine —
/// only a segment that is exactly `..` is rejected.
pub fn validate_path(path: &str) -> Result<(), String> {
    if !path.starts_with('/') || path.contains("://") {
        return Err("Invalid API path.".to_string());
    }
    if path.starts_with("//") {
        return Err("Invalid API path.".to_string());
    }
    if path.contains('\\') {
        return Err("Invalid API path.".to_string());
    }
    if path.split('/').any(|segment| segment == "..") {
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

    #[test]
    fn rejects_a_protocol_relative_path() {
        assert!(validate_path("//evil.example.com/x").is_err());
    }

    #[test]
    fn rejects_a_backslash_path() {
        assert!(validate_path("/\\evil.example.com/x").is_err());
    }

    #[test]
    fn rejects_a_leading_dot_dot_traversal() {
        assert!(validate_path("/../../evil").is_err());
    }

    #[test]
    fn rejects_a_mid_path_dot_dot_traversal() {
        assert!(validate_path("/orders/../../admin").is_err());
    }

    #[test]
    fn accepts_a_legitimate_path_with_dots_in_the_query() {
        assert!(validate_path("/orders/123/receipt?format=v1.2").is_ok());
    }
}
