import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import { useAuthStore } from '../auth/store';

const Layout = () => {
  const navigate = useNavigate();
  const { user, logout } = useAuthStore();

  const handleLogout = () => {
    logout();
    navigate('/login', { replace: true });
  };

  return (
    <div className="app-shell">
      <header style={{ padding: '1.5rem 2rem', background: 'white', borderBottom: '1px solid #e2e8f0' }}>
        <nav>
          <strong style={{ marginRight: 'auto' }}>Monotickets</strong>
          <NavLink to="/" end>
            Dashboard
          </NavLink>
          <NavLink to="/users">Usuarios</NavLink>
          {user && (
            <span style={{ marginLeft: 'auto', paddingRight: '1rem' }}>
              {user.name} · {user.role}
            </span>
          )}
          <button type="button" onClick={handleLogout}>
            Cerrar sesión
          </button>
        </nav>
      </header>
      <main className="app-content">
        <Outlet />
      </main>
    </div>
  );
};

export default Layout;
