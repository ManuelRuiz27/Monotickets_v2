import type { QueryKey, UseQueryOptions } from '@tanstack/react-query';

export type AppQueryOptions<
  TQueryFnData,
  TData = TQueryFnData,
  TQueryKey extends QueryKey = QueryKey
> = Omit<UseQueryOptions<TQueryFnData, unknown, TData, TQueryKey>, 'queryKey' | 'queryFn'>;
