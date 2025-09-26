import type { ReactNode } from 'react';
import { useEffect, useMemo, useState } from 'react';
import {
  CellContext,
  ColumnDef,
  flexRender,
  getCoreRowModel,
  useReactTable,
} from '@tanstack/react-table';
import { apiFetch, ApiError } from '../api/client';
import { AuthUser } from '../auth/store';

interface UsersResponse {
  data: AuthUser[];
}

const fallbackUsers: AuthUser[] = [
  { id: '1', name: 'Ana Campos', email: 'ana@example.com', role: 'organizer' },
  { id: '2', name: 'Luis Pérez', email: 'luis@example.com', role: 'superadmin' },
];

const defaultCellRenderer = (ctx: CellContext<AuthUser, unknown>) => ctx.getValue() as ReactNode;

const UsersList = () => {
  const [users, setUsers] = useState<AuthUser[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const fetchUsers = async () => {
      setLoading(true);
      setError(null);
      try {
        const response = await apiFetch<UsersResponse>('/users');
        setUsers(response.data);
      } catch (err) {
        const message = err instanceof ApiError ? err.message : 'No se pudieron cargar los usuarios';
        setError(message);
        setUsers(fallbackUsers);
      } finally {
        setLoading(false);
      }
    };

    fetchUsers();
  }, []);

  const columns = useMemo<ColumnDef<AuthUser>[]>(
    () => [
      { header: 'Nombre', accessorKey: 'name' },
      { header: 'Correo', accessorKey: 'email' },
      { header: 'Rol', accessorKey: 'role' },
    ],
    []
  );

  const table = useReactTable({ data: users, columns, getCoreRowModel: getCoreRowModel() });

  return (
    <section>
      <header style={{ marginBottom: '1.5rem' }}>
        <h1 style={{ marginTop: 0 }}>Usuarios</h1>
        <p style={{ color: '#475569' }}>
          Consulta la lista de usuarios con acceso. Solo lectura para mantener la consistencia.
        </p>
      </header>
      {loading && <p>Cargando usuarios…</p>}
      {error && <div className="alert">{error}</div>}
      <table className="table">
        <thead>
          {table.getHeaderGroups().map((headerGroup) => (
            <tr key={headerGroup.id}>
              {headerGroup.headers.map((header) => (
                <th key={header.id}>
                  {header.isPlaceholder ? null : flexRender(header.column.columnDef.header, header.getContext())}
                </th>
              ))}
            </tr>
          ))}
        </thead>
        <tbody>
          {table.getRowModel().rows.map((row) => (
            <tr key={row.id}>
              {row.getVisibleCells().map((cell) => (
                <td key={cell.id}>
                  {flexRender(cell.column.columnDef.cell ?? defaultCellRenderer, cell.getContext())}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  );
};

export default UsersList;
