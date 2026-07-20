/// Everything a till needs from a printer. The drawer is not a second device: it is
/// kicked by the printer over RJ11, so a drawer pulse is just more bytes.
///
/// Only MockPrinter implements this today. The first real driver will be network (raw
/// TCP 9100), then USB and serial — each is an impl of this trait and nothing else has
/// to change.
pub trait Printer: Send + Sync {
    fn write(&self, bytes: &[u8]) -> Result<(), String>;
}
