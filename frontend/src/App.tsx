import { Route, Routes } from 'react-router-dom';
import Dashboard from './pages/Dashboard';
import Login from './pages/Login';
import UsersList from './pages/UsersList';
import { RequireAuth, RequireRole } from './routes/guards';
import Layout from './components/Layout';

function App() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route
        element={
          <RequireAuth>
            <Layout />
          </RequireAuth>
        }
      >
        <Route index element={<Dashboard />} />
        <Route
          path="users"
          element={
            <RequireRole roles={['organizer', 'superadmin']}>
              <UsersList />
            </RequireRole>
          }
        />
      </Route>
      <Route path="*" element={<Login />} />
    </Routes>
  );
}

export default App;
