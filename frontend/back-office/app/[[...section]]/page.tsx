import { AdminApp } from '../../src/admin/AdminApp'

// AdminApp is one deliberate client boundary under this server shell: everything it
// does (tokens in localStorage, login, the nav shell) is interactive by nature. There
// is nothing to render ahead of time except the chassis.
export default function Page() {
  return <AdminApp />
}
