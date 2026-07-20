import { invoke } from '@tauri-apps/api/core'
import { inShell } from './transport'
import type { Receipt } from './api'

/**
 * Thin wrappers over the desktop shell's commands. Every one of these is a no-op in a
 * browser tab: the register is fully usable without the shell, which is why v1 shipped
 * before it existed.
 */
export type ShellConfig = { server_url: string | null }

export async function getConfig(): Promise<ShellConfig | null> {
  if (!inShell()) return null
  return invoke<ShellConfig>('get_config')
}

export async function setServerUrl(url: string): Promise<void> {
  if (!inShell()) return
  await invoke('set_server_url', { url })
}

/**
 * Probes a candidate address through Rust. Deliberately NOT a webview `fetch`: that would
 * be cross-origin from `tauri://localhost` and die on CORS, which is the whole reason API
 * traffic detours through Rust in the first place.
 */
export async function checkServer(url: string): Promise<boolean> {
  if (!inShell()) return false
  return invoke<boolean>('check_server', { url })
}

/** True when the shell can actually drive hardware — a browser tab never can. */
export const hasHardware = inShell

export async function printReceipt(receipt: Receipt, currency: string): Promise<void> {
  await invoke('print_receipt', { receipt, currency })
}

export async function openDrawer(): Promise<void> {
  await invoke('open_drawer')
}
