export interface UserRole {
  id: string;
  code: string;
  name: string;
  tenant_id?: string | null;
}

export interface UserResource {
  id: string;
  tenant_id?: string | null;
  name: string;
  email: string;
  phone?: string | null;
  is_active: boolean;
  roles: UserRole[];
  created_at?: string | null;
  updated_at?: string | null;
}

export interface UsersListResponse {
  data: UserResource[];
  meta: {
    page: number;
    per_page: number;
    total: number;
    total_pages: number;
  };
}

export interface UserSingleResponse {
  data: UserResource;
}

export interface CreateUserPayload {
  name: string;
  email: string;
  phone?: string | null;
  password: string;
  roles: string[];
  is_active: boolean;
  tenant_id?: string | null;
}

export interface UpdateUserPayload {
  name?: string;
  phone?: string | null;
  is_active?: boolean;
  roles?: string[];
}
