import { Register } from '../src/register/Register'

// The register is one deliberate client boundary under this server shell: everything it
// does (tokens in localStorage, scanning, tendering) is interactive by nature. There is
// nothing to render ahead of time except the chassis.
export default function Page() {
  return <Register />
}
