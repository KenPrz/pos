use crate::hardware::driver::Printer;
use std::path::PathBuf;
use std::time::{SystemTime, UNIX_EPOCH};

/// Writes the exact bytes it would have sent to a file, so the whole hardware path is
/// reviewable with no printer in the building. Groundwork, per the spec.
pub struct MockPrinter {
    dir: PathBuf,
}

impl MockPrinter {
    pub fn new(dir: PathBuf) -> Self {
        Self { dir }
    }
}

impl Printer for MockPrinter {
    fn write(&self, bytes: &[u8]) -> Result<(), String> {
        std::fs::create_dir_all(&self.dir).map_err(|e| e.to_string())?;

        let stamp = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .map_err(|e| e.to_string())?
            .as_nanos();

        std::fs::write(self.dir.join(format!("{stamp}.bin")), bytes).map_err(|e| e.to_string())
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::hardware::driver::Printer;

    #[test]
    fn writes_the_bytes_it_was_given_to_a_file() {
        let dir = std::env::temp_dir().join(format!("pos-mock-{}", std::process::id()));
        let printer = MockPrinter::new(dir.clone());

        printer.write(b"hello printer").unwrap();

        let written: Vec<_> = std::fs::read_dir(&dir)
            .unwrap()
            .filter_map(Result::ok)
            .collect();
        assert_eq!(written.len(), 1);
        assert_eq!(std::fs::read(written[0].path()).unwrap(), b"hello printer");

        std::fs::remove_dir_all(dir).ok();
    }

    #[test]
    fn creates_its_directory_on_first_use() {
        let dir = std::env::temp_dir().join(format!("pos-mock-new-{}", std::process::id()));
        std::fs::remove_dir_all(&dir).ok();

        MockPrinter::new(dir.clone()).write(b"x").unwrap();

        assert!(dir.exists());
        std::fs::remove_dir_all(dir).ok();
    }
}
