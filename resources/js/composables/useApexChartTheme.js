import { onBeforeUnmount, onMounted, ref } from 'vue';

// Tracks the `dark` class on <html> (toggled by AuthenticatedLayout) so
// ApexCharts text/grid colors can react to theme changes.
export function useApexChartTheme() {
    const isDark = ref(document.documentElement.classList.contains('dark'));
    let observer;

    onMounted(() => {
        observer = new MutationObserver(() => {
            isDark.value = document.documentElement.classList.contains('dark');
        });
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    });

    onBeforeUnmount(() => observer?.disconnect());

    const chartForeColor = () => (isDark.value ? '#cbd5e1' : '#374151');
    const chartGridColor = () => (isDark.value ? '#334155' : '#f1f5f9');
    const chartTooltipTheme = () => (isDark.value ? 'dark' : 'light');
    const chartAxisTitleColor = () => (isDark.value ? '#94a3b8' : '#9ca3af');

    return { isDark, chartForeColor, chartGridColor, chartTooltipTheme, chartAxisTitleColor };
}
