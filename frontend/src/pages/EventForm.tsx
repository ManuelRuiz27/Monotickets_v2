import { useParams } from 'react-router-dom';
import EventForm from '../components/events/EventForm';

const EventFormPage = () => {
  const params = useParams<{ eventId?: string }>();
  return <EventForm eventId={params.eventId} />;
};

export default EventFormPage;
