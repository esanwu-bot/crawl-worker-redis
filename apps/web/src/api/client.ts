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
    const method = (error?.config?.method || 'get').toLowerCase();
    // GET 失败由调用方用演示数据兜底，不打扰用户；写操作失败才提示。
    if (method !== 'get') {
      const status = error?.response?.status;
      const detail =
        error?.response?.data?.error ||
        error?.response?.data?.message ||
        error?.message ||
        '请求失败';
      if (!error.response) {
        message.error(`无法连接采集引擎 API (${baseURL})：请确认 crawler-api 已启动`);
      } else {
        message.error(`[${status}] ${detail}`);
      }
    }
    return Promise.reject(error);
  },
);

export default http;
