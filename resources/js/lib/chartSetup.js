import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    BarElement,
    LineElement,
    PointElement,
    ArcElement,
    Title,
    Tooltip,
    Legend,
    Filler,
} from 'chart.js';

ChartJS.register(
    CategoryScale,
    LinearScale,
    BarElement,
    LineElement,
    PointElement,
    ArcElement,
    Title,
    Tooltip,
    Legend,
    Filler
);

export const CHART_COLORS = [
    '#2563eb', '#0ea5e9', '#14b8a6', '#f59e0b', '#ef4444',
    '#8b5cf6', '#ec4899', '#22c55e', '#64748b',
];

export default ChartJS;
