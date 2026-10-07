import adminApi from './adminApi'
import type { AdminDashboardResponse, AdminDashboardStats } from '@/types/adminDashboard'

export const getAdminDashboard = async (): Promise<AdminDashboardStats> =>
  (await adminApi.get<AdminDashboardResponse>('/admin/dashboard')).data.data
