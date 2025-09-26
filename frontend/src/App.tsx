import { Route, Routes } from 'react-router-dom';
import Dashboard from './pages/Dashboard';
import Login from './pages/Login';
import UsersList from './pages/UsersList';
import EventsList from './pages/EventsList';
import EventForm from './pages/EventForm';
import EventDetail from './pages/EventDetail';
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
        <Route
          path="events"
          element={
            <RequireRole roles={['organizer', 'superadmin']}>
              <EventsList />
            </RequireRole>
          }
        />
        <Route
          path="events/:eventId"
          element={
            <RequireRole roles={['organizer', 'superadmin']}>
              <EventDetail />
            </RequireRole>
          }
        />
        <Route
          path="events/new"
          element={
            <RequireRole roles={['organizer', 'superadmin']}>
              <EventForm />
            </RequireRole>
          }
        />
        <Route
          path="events/:eventId/edit"
          element={
            <RequireRole roles={['organizer', 'superadmin']}>
              <EventForm />
            </RequireRole>
          }
        />
      </Route>
      <Route path="*" element={<Login />} />
    </Routes>
  );
}

export default App;
