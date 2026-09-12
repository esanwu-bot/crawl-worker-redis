import { ref, watch } from 'vue';

// 明暗主题（antd algorithm + body.dark 覆盖）。默认浅色，遵循本地存储。
export const dark = ref(localStorage.getItem('cw-theme') === 'dark');

watch(dark, (v) => {
  localStorage.setItem('cw-theme', v ? 'dark' : 'light');
  document.body.classList.toggle('dark', v);
});

// 初始化
if (dark.value) document.body.classList.add('dark');
