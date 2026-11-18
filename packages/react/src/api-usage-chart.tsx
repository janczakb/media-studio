import {useCallback, useEffect, useId, useState} from 'react';
import {createRoot} from 'react-dom/client';
import {Area, AreaChart, Bar, CartesianGrid, ComposedChart, Line, LineChart, Rectangle, ResponsiveContainer, Tooltip, XAxis, YAxis, type BarShapeProps, type TooltipContentProps} from 'recharts';
import type {ApiUsagePoint} from './api-access';
import type {ShareAnalytics} from './management-contracts';

export interface UsageChartOptions {points:ApiUsagePoint[];locale:string;requestsLabel:string;errorsLabel:string;label:string}
function UsageChart({points,locale,requestsLabel,errorsLabel,label}:UsageChartOptions){
  const date=(s:string)=>new Intl.DateTimeFormat(locale,{month:'short',day:'numeric',timeZone:'UTC'}).format(new Date(s+'T00:00:00Z'));
  const hasErrors=points.some(p=>p.errors>0);
  return <ResponsiveContainer width="100%" height="100%" minWidth={0} debounce={80}>
    <LineChart data={points} margin={{top:16,right:16,bottom:8,left:0}} accessibilityLayer aria-label={label}>
      <CartesianGrid vertical={false} stroke="var(--border)"/>
      <XAxis dataKey="date" tickFormatter={date} axisLine={false} tickLine={false} minTickGap={48} tick={{fill:'var(--muted)',fontSize:11}}/>
      <YAxis allowDecimals={false} domain={[0,(max:number)=>Math.max(1,max)]} axisLine={false} tickLine={false} width={36} tick={{fill:'var(--muted)',fontSize:11}}/>
      <Tooltip labelFormatter={value=>date(String(value))} isAnimationActive={false} contentStyle={{background:'var(--background)',border:'1px solid var(--border)',borderRadius:12,fontSize:12,color:'var(--foreground)'}}/>
      <Line type="monotone" dataKey="requests" name={requestsLabel} stroke="var(--accent)" strokeWidth={2} dot={false} activeDot={{r:4}} isAnimationActive={false}/>
      {hasErrors&&<Line type="monotone" dataKey="errors" name={errorsLabel} stroke="var(--danger,#e5484d)" strokeWidth={1.5} strokeDasharray="4 4" dot={false} isAnimationActive={false}/>}
    </LineChart>
  </ResponsiveContainer>;
}

/** Separate on-demand renderer; no chart dependency enters the library's initial bundle. */
export function mountUsageChart(element:HTMLElement,options:UsageChartOptions){
  const root=createRoot(element);root.render(<UsageChart {...options}/>);
  return {update(value:UsageChartOptions){root.render(<UsageChart {...value}/>);},destroy(){queueMicrotask(()=>root.unmount());}};
}

interface ShareChartOptions {
  points: ShareAnalytics['points'];
  locale: string;
  label: string;
  durationLabel: string;
  mode: 'area' | 'combo';
  onActiveDay: (date: string | null) => void;
}

function ShareChartCursor({active, label, onActiveDay}: {active: boolean; label: TooltipContentProps['label']} & Pick<ShareChartOptions, 'onActiveDay'>) {
  useEffect(() => onActiveDay(active && typeof label === 'string' ? label : null), [active, label, onActiveDay]);
  return null;
}

function ShareChart({points, locale, label, durationLabel, mode, onActiveDay}: ShareChartOptions) {
  const [activeDay, setActiveDay] = useState<string | null>(null);
  const reportActiveDay = useCallback((day: string | null) => {
    setActiveDay(day);
    onActiveDay(day);
  }, [onActiveDay]);
  const gradient = 'share-area-' + useId().replace(/[^a-z0-9-]/gi, '');
  const date = new Intl.DateTimeFormat(locale, {month: 'short', day: 'numeric', timeZone: 'UTC'});
  const number = new Intl.NumberFormat(locale, {notation: 'compact'});
  const visits = 'var(--fms-share-series)';
  const duration = 'var(--fms-share-duration)';
  const Chart = mode === 'combo' ? ComposedChart : AreaChart;
  return <ResponsiveContainer width="100%" height="100%" minWidth={0} debounce={80}>
    <Chart data={points} margin={{top:12,right:12,bottom:0,left:0}} accessibilityLayer aria-label={mode === 'combo' ? label + ' / ' + durationLabel : label}>
      <defs><linearGradient id={gradient} x1="0" y1="0" x2="0" y2="1">
        <stop offset="0%" stopColor={visits} stopOpacity={.35}/>
        <stop offset="100%" stopColor={visits} stopOpacity={.025}/>
      </linearGradient></defs>
      <CartesianGrid vertical={false} stroke="var(--fms-share-divider)" strokeDasharray="3 4"/>
      <XAxis dataKey="date" tickFormatter={value => date.format(new Date(value + 'T00:00:00Z'))} interval="preserveStartEnd"
        axisLine={false} tickLine={false} minTickGap={25} tickMargin={9} tick={{fill:'var(--fms-share-muted)',fontSize:11}}/>
      <YAxis yAxisId="visits" allowDecimals={false} domain={[0,(max:number)=>Math.max(4,max)]} tickFormatter={value=>number.format(value)}
        width={38} axisLine={false} tickLine={false} tickMargin={8} tick={{fill:'var(--fms-share-muted)',fontSize:11}}/>
      {mode === 'combo' && <YAxis yAxisId="duration" orientation="right" tickFormatter={value => number.format(value) + 's'}
        width={40} axisLine={false} tickLine={false} tickMargin={8} tick={{fill:'var(--fms-share-muted)',fontSize:11}}/>}
      <Tooltip cursor={{stroke:'var(--fms-share-muted)',strokeDasharray:'4 4',strokeWidth:1}}
        isAnimationActive={false} content={props => <ShareChartCursor active={props.active} label={props.label} onActiveDay={reportActiveDay}/>}/>
      {mode === 'combo' ? <>
        <Bar yAxisId="visits" dataKey="visits" name={label} maxBarSize={26} isAnimationActive={false}
          shape={({x, y, width, height, originalDataIndex}: BarShapeProps) => <Rectangle className="fms-share-combo-bar"
            x={x} y={y} width={width} height={height} radius={6} fill={visits}
            fillOpacity={activeDay && points[originalDataIndex]?.date !== activeDay ? .35 : 1}/>}/>
        <Line yAxisId="duration" type="monotone" dataKey="averageSeconds" name={durationLabel} stroke={duration}
          strokeWidth={2} dot={false} activeDot={{r:4,fill:duration,stroke:'var(--fms-share-panel)',strokeWidth:2}} isAnimationActive={false}/>
      </> : <Area yAxisId="visits" type="monotone" dataKey="visits" name={label} stroke={visits} fill={`url(#${gradient})`}
        strokeWidth={2} dot={false} activeDot={{r:4,fill:visits,stroke:'var(--fms-share-panel)',strokeWidth:2}} isAnimationActive={false}/>}
    </Chart>
  </ResponsiveContainer>;
}

export function mountShareChart(element:HTMLElement, options:ShareChartOptions) {
  const root = createRoot(element);
  root.render(<ShareChart {...options}/>);
  return {destroy(){queueMicrotask(()=>root.unmount());}};
}
