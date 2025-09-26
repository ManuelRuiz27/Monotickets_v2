import { Navigate, useParams } from 'react-router-dom';
import EventDetail from '../components/events/EventDetail';

const EventDetailPage = () => {
  const params = useParams<{ eventId?: string }>();
  const eventId = params.eventId;

  if (!eventId) {
    return <Navigate to="/events" replace />;
  }

  return <EventDetail eventId={eventId} />;
};

export default EventDetailPage;
