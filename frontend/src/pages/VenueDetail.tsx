import { Navigate, useParams } from 'react-router-dom';
import VenueDetail from '../components/events/VenueDetail';

const VenueDetailPage = () => {
  const params = useParams<{ eventId?: string; venueId?: string }>();
  const eventId = params.eventId;
  const venueId = params.venueId;

  if (!eventId || !venueId) {
    return <Navigate to="/events" replace />;
  }

  return <VenueDetail eventId={eventId} venueId={venueId} />;
};

export default VenueDetailPage;
