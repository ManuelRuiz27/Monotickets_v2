import { Route, Routes } from 'react-router-dom';
import Dashboard from './pages/Dashboard';
import Login from './pages/Login';
import UsersList from './pages/UsersList';
import EventsList from './pages/EventsList';
import EventForm from './pages/EventForm';
import EventDetail from './pages/EventDetail';
import VenueDetail from './pages/VenueDetail';
import GuestDetail from './pages/GuestDetail';
import Hostess from './pages/Hostess';
import AdminAnalytics from './pages/AdminAnalytics';
import TenantSettings from './pages/TenantSettings';
import AdminTenants from './pages/AdminTenants';
import TenantUsage from './pages/TenantUsage';
import Billing from './pages/Billing';
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
          path="settings"
          element={
            <RequireRole roles={['organizer', 'superadmin']}>
              <TenantSettings />
            </RequireRole>
          }
        />
        <Route
          path="billing"
          element={
            <RequireRole roles={['tenant_owner', 'superadmin']}>
              <Billing />
            </RequireRole>
          }
        />
        <Route
          path="admin/analytics"
          element={
            <RequireRole roles={['superadmin']}>
              <AdminAnalytics />
            </RequireRole>
          }
        />
        <Route
          path="admin/tenants"
          element={
            <RequireRole roles={['superadmin']}>
              <AdminTenants />
            </RequireRole>
          }
        />
        <Route
          path="admin/tenants/:tenantId/usage"
          element={
            <RequireRole roles={['superadmin']}>
              <TenantUsage />
            </RequireRole>
          }
        />
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
          path="events/:eventId/guests/:guestId"
          element={
            <RequireRole roles={['organizer', 'superadmin']}>
              <GuestDetail />
            </RequireRole>
          }
        />
        <Route
          path="events/:eventId/venues/:venueId"
          element={
            <RequireRole roles={['organizer', 'superadmin']}>
              <VenueDetail />
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
        <Route
          path="hostess"
          element={
            <RequireRole roles={['hostess', 'organizer']}>
              <Hostess />
            </RequireRole>
          }
        />
      </Route>
      <Route path="*" element={<Login />} />
    </Routes>
  );
}

export default App;
