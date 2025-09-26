import { useAuthStore } from '../auth/store';

const Dashboard = () => {
  const { user } = useAuthStore();

  return (
    <section>
      <h1 style={{ marginTop: 0 }}>Panel de control</h1>
      <p>Bienvenido de nuevo{user ? `, ${user.name}` : ''}. Selecciona una opción del menú para comenzar.</p>
    </section>
  );
};

export default Dashboard;
