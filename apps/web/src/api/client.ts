import axios, { type AxiosInstance } from 'axios';
import { message } from 'ant-design-vue';

const baseURL = (import.meta.env.VITE_API_BASE as string) || '/api/v1';

const http: AxiosInstance = axios.create({
  baseURL,
  timeout: 20000,
});

http.interceptors.response.use(
  (resp) => resp,
  (error) => {
    const status = error?.response?.status;
    const detail =
      error?.response?.data?.error ||
      error?.response?.data?.message ||
      error?.message ||
      '请求失败';
    // 后台不可用/未启动时给出明确提示，而不是静默空白。
    if (!error.response) {
      message.error(`无法连接采集引擎 API (${baseURL})：请确认 crawler-api 已启动`);
    } else {
      message.error(`[${status}] ${detail}`);
    }
    return Promise.reject(error);
  },
);

export default http;
