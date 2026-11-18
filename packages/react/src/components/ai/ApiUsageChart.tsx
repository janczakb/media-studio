import {TableEmptyState} from '../TableEmptyState';
import {Button, Spinner} from '@heroui/react';
import {useEffect, useRef, useState} from 'react';
import type {ApiUsagePoint} from '../../api-access';
import type {UsageChartOptions} from '../../api-usage-chart';
import type en from './locales/en';

type ChartModule=typeof import('../../api-usage-chart');
let modulePromise:Promise<ChartModule>|undefined;
function loadChart(){return modulePromise??=import(new URL('./api-usage-chart.js',import.meta.url).href).catch(error=>{modulePromise=undefined;throw error;});}
export function ApiUsageChart({points,t,locale}:{points:ApiUsagePoint[];t:Record<keyof typeof en,string>;portal:HTMLDivElement;locale:string}) {
  const host=useRef<HTMLDivElement>(null);
  const chart=useRef<ReturnType<ChartModule['mountUsageChart']>|null>(null);
  const props=useRef<UsageChartOptions>({points,locale,requestsLabel:t.apiTotal,errorsLabel:t.apiErrors,label:t.apiChart});
  props.current={points,locale,requestsLabel:t.apiTotal,errorsLabel:t.apiErrors,label:t.apiChart};
  const [status,setStatus]=useState<'loading'|'ready'|'error'>('loading');const[attempt,setAttempt]=useState(0);
  useEffect(()=>{let disposed=false;setStatus('loading');void loadChart().then(module=>{if(disposed||!host.current)return;chart.current=module.mountUsageChart(host.current,props.current);setStatus('ready');}).catch(()=>{if(!disposed)setStatus('error');});return()=>{disposed=true;chart.current?.destroy();chart.current=null;};},[attempt]);
  useEffect(()=>{chart.current?.update(props.current);},[points,t,locale]);
  return <>
    <div className="fms-api-chart-frame"><div className="fms-api-chart" ref={host}/>{status!=='ready'&&<div className="fms-api-chart-status" role="status">{status==='loading'?<Spinner size="sm"/>:<Button size="sm" onPress={()=>setAttempt(v=>v+1)}>{t.retry}</Button>}</div>}</div>
    {!points.some(p=>p.requests>0)&&<p className="fms-api-muted">{t.apiNoUsage}</p>}
    <details className="fms-api-data"><summary>{t.apiShowTable}</summary><table><thead><tr><th>UTC</th><th>{t.apiTotal}</th><th>{t.apiSuccess}</th><th>{t.apiErrors}</th></tr></thead><tbody>{points.length===0&&<tr><td colSpan={4}><TableEmptyState kind="usage" label={t.apiNoUsage}/></td></tr>}{points.map(p=><tr key={p.date}><td>{p.date}</td><td>{p.requests}</td><td>{p.successes}</td><td>{p.errors}</td></tr>)}</tbody></table></details>
  </>;
}
