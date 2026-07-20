// Nothing calls into this module yet — the commands that wire it up land in the next
// task. Until then every item here is unused, which clippy would otherwise flag as
// 18 separate `dead_code` errors. Delete this allow once real callers exist.
#![allow(dead_code)]

pub mod escpos;
