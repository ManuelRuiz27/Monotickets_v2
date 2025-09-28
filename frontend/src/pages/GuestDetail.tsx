import { Navigate, useParams } from 'react-router-dom';
import GuestDetail from '../components/guests/GuestDetail';

const GuestDetailPage = () => {
  const params = useParams<{ eventId?: string; guestId?: string }>();
  const eventId = params.eventId;
  const guestId = params.guestId;

  if (!eventId || !guestId) {
    return <Navigate to="/events" replace />;
  }

  return <GuestDetail eventId={eventId} guestId={guestId} />;
};

export default GuestDetailPage;
