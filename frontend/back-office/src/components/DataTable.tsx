import type { ReactNode } from 'react'
import { EmptyState } from './EmptyState'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from './ui/table'

export interface DataTableColumn<T> {
  key: string
  header: string
  className?: string
  render?: (row: T) => ReactNode
}

export interface DataTableProps<T> {
  columns: DataTableColumn<T>[]
  rows: T[]
  toolbar?: ReactNode
  empty?: { title: string; description?: string }
}

// Table + toolbar slot + EmptyState wiring.
export function DataTable<T extends Record<string, unknown>>({
  columns,
  rows,
  toolbar,
  empty,
}: DataTableProps<T>) {
  return (
    <div>
      {toolbar ? (
        <div className="mb-md flex items-center justify-between gap-md">{toolbar}</div>
      ) : null}
      {rows.length === 0 ? (
        <EmptyState title={empty?.title ?? 'Nothing here yet'} description={empty?.description} />
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              {columns.map((col) => (
                <TableHead key={col.key} className={col.className}>
                  {col.header}
                </TableHead>
              ))}
            </TableRow>
          </TableHeader>
          <TableBody>
            {rows.map((row, index) => (
              <TableRow key={index} zebra>
                {columns.map((col) => (
                  <TableCell key={col.key} className={col.className}>
                    {col.render ? col.render(row) : (row[col.key] as ReactNode)}
                  </TableCell>
                ))}
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
    </div>
  )
}
