import axios, { type InternalAxiosRequestConfig } from 'axios'
import {
    getSessionVersion,
    isLoginRequest,
    isMemberRequest,
    reportSessionFailure,
    SessionInvalidatedError,
} from './sessionState'

type SessionRequestConfig = InternalAxiosRequestConfig & { sessionVersion?: number }

const api = axios.create({
    baseURL: import.meta.env.VITE_API_BASE_URL,
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        Accept: 'application/json',
    },
})

api.interceptors.request.use((config: SessionRequestConfig) => {
    config.sessionVersion = getSessionVersion()
    return config
})

api.interceptors.response.use(
    (response) => {
        const config = response.config as SessionRequestConfig
        if ((isMemberRequest(config.url) || isLoginRequest(config.url)) && config.sessionVersion !== getSessionVersion()) {
            throw new SessionInvalidatedError()
        }
        return response
    },
    (error) => {
        if (axios.isAxiosError(error)) {
            const config = error.config as SessionRequestConfig | undefined
            const status = error.response?.status
            const isLogin = isLoginRequest(config?.url)
            const isMember = isMemberRequest(config?.url)

            if (config?.sessionVersion !== undefined && (isLogin || isMember)) {
                if (status === 403 && error.response?.data?.code === 'ACCOUNT_DISABLED') {
                    reportSessionFailure(config.sessionVersion, 'disabled', !isLogin)
                } else if (status === 401 && isMember) {
                    reportSessionFailure(config.sessionVersion, 'expired', true)
                }
            }
        }
        console.error(
            'API Error:',
            error.response?.data ?? error.message,
        )

        return Promise.reject(error)
    }
)

export default api
