// Central chart.js registration (Epic 7 charting foundation). chart.js v4 is
// tree-shakeable and auto-registers nothing, so we register only the elements,
// scales, and plugins the admin KpiTile/TrendChart components use — keeping the
// bundle lean. Import this module for its side effects before rendering a chart.
import {
    CategoryScale,
    Chart,
    Filler,
    Legend,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
} from 'chart.js';

Chart.register(CategoryScale, LinearScale, LineElement, PointElement, Filler, Tooltip, Legend);
