import { PropsWithChildren } from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { Role, useAuthStore } from '../auth/store';

interface RequireRoleProps {
  roles: Role[];
}

export const RequireAuth = ({ children }: PropsWithChildren) => {
  const { token } = useAuthStore();
  const location = useLocation();

  if (!token) {
    return <Navigate to="/login" replace state={{ from: location }} />;
  }

  return <>{children}</>;
};

export const RequireRole = ({ children, roles }: PropsWithChildren<RequireRoleProps>) => {
  const { user } = useAuthStore();
  const location = useLocation();

  if (!user) {
    return <Navigate to="/login" replace state={{ from: location }} />;
  }

  if (!roles.includes(user.role)) {
    return <Navigate to="/" replace />;
  }

  return <>{children}</>;
};
