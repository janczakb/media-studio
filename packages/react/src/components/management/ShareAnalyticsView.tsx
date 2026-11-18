import {Button, Dropdown, Label, Spinner, Tabs} from '@heroui/react';
import {ArrowDown01Icon, ArrowLeft01Icon, ArrowRight01Icon, Calendar03Icon, Globe02Icon} from '@hugeicons/core-free-icons';
import {useEffect, useRef, useState} from 'react';
import type {ManagementTransport, ShareAnalytics, ShareRecord} from '../../management-contracts';
import {Icon, errorText} from '../../ui';
import {useDateText, useStudioTimeZone} from '../layout/TimePreferences';
import {useManagementMessages, type ManagementLocale} from './shared';

type ChartModule = typeof import('../../api-usage-chart');
let chartModule: Promise<ChartModule> | undefined;
type FlagModule = typeof import('../../share-country-flags');
let flagModule: Promise<FlagModule> | undefined;
const periods = [7, 30, 90] as const;
const daysPerPage = 7;
const utcDay = (value: string) => new Date(value + 'T00:00:00Z');

export function ShareAnalyticsView({workspace, record, transport, locale, portal}: {
  workspace: string;
  record: ShareRecord;
  transport: ManagementTransport;
  locale: ManagementLocale;
  portal: HTMLElement | null;
}) {
  const t = useManagementMessages();
  const [days, setDays] = useState<(typeof periods)[number]>(() => Date.now() - Date.parse(record.createdAt) < 7 * 86_400_000 ? 7 : 30);
  const [view, setView] = useState('overview');
  const [mode, setMode] = useState<'area' | 'combo'>('area');
  const [activeDay, setActiveDay] = useState<string | null>(null);
  const [page, setPage] = useState(1);
  const [data, setData] = useState<ShareAnalytics | null>(null);
  const [flags, setFlags] = useState<FlagModule['Flags'] | null>(null);
  const [error, setError] = useState('');
  const [attempt, retry] = useState(0);
  const timeZone = useStudioTimeZone();
  const fullDate = useDateText(locale);

  useEffect(() => {
    const controller = new AbortController();
    setData(null);
    setError('');
    void transport.shareAnalytics?.(workspace, record.id, days, controller.signal).then(value => {
      if (!controller.signal.aborted) setData(value);
    }).catch(reason => {
      if (!controller.signal.aborted) setError(errorText(reason));
    });
    return () => controller.abort();
  }, [transport, workspace, record.id, days, attempt]);

  const hasLocations = Boolean(data?.countries.length);
  useEffect(() => {
    if (!hasLocations) return;
    let active = true;
    flagModule ??= import(new URL('./share-country-flags.js', import.meta.url).href).catch(reason => {
      flagModule = undefined;
      throw reason;
    });
    void flagModule.then(({Flags}) => {if (active) setFlags(Flags);}).catch(() => {});
    return () => {active = false;};
  }, [hasLocations]);

  const number = new Intl.NumberFormat(locale);
  const shortDate = new Intl.DateTimeFormat(locale, {month: 'short', day: 'numeric', timeZone});
  const rangeDate = new Intl.DateTimeFormat(locale, {month: 'short', day: 'numeric', timeZone: 'UTC'});
  const regions = new Intl.DisplayNames([locale], {type: 'region'});
  const first = data?.points[0]?.date;
  const last = data?.points.at(-1)?.date;
  const range = first && last ? rangeDate.formatRange(utcDay(first), utcDay(last)) : '';
  const activePoint = activeDay ? data?.points.find(point => point.date === activeDay) : undefined;
  const hasDuration = Boolean(data?.points.some(point => point.averageSeconds != null));
  const chartMode = hasDuration ? mode : 'area';
  const durationText = (seconds: number | null | undefined) => seconds == null ? '—'
    : `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
  const maxCountryVisits = Math.max(1, ...data?.countries.map(row => row.visits) ?? []);

  return <div className="fms-share-analytics">
    <div className="fms-share-analytics-toolbar">
      <span className="fms-share-analytics-period">{range}</span>
      <Dropdown>
        <Button variant="secondary" size="sm" className="fms-share-period-trigger" aria-label={t.period + ': ' + days + ' ' + t.days}>
          <Icon icon={Calendar03Icon} size={16}/>
          <span>{days} {t.days}</span>
          <Icon icon={ArrowDown01Icon} size={14}/>
        </Button>
        <Dropdown.Popover className="fms-share-period-menu" placement="bottom end" offset={6} isNonModal
          {...(portal ? {UNSTABLE_portalContainer: portal} : {})}>
          <Dropdown.Menu aria-label={t.period} selectionMode="single" disallowEmptySelection selectedKeys={[String(days)]}
            onAction={key => {
              const period = periods.find(candidate => String(candidate) === key);
              if (period && period !== days) {setDays(period); setPage(1); setActiveDay(null);}
            }}>
            {periods.map(value => {
              const label = value + ' ' + t.days;
              return <Dropdown.Item className="ps-2" key={value} id={String(value)} textValue={label}>
                <Label>{label}</Label><Dropdown.ItemIndicator className="static ms-auto translate-none"/>
              </Dropdown.Item>;
            })}
          </Dropdown.Menu>
        </Dropdown.Popover>
      </Dropdown>
    </div>
    <Tabs className="fms-share-report-tabs" selectedKey={view} onSelectionChange={key => {setView(String(key)); setActiveDay(null);}}>
      <Tabs.ListContainer><Tabs.List aria-label={t.linkAnalytics}>
        <Tabs.Tab id="overview">{t.analyticsOverview}<Tabs.Indicator/></Tabs.Tab>
        <Tabs.Tab id="daily">{t.dailyVisits}<Tabs.Indicator/></Tabs.Tab>
      </Tabs.List></Tabs.ListContainer>
      {error ? <div className="fms-share-report-state" role="alert">{error}<Button size="sm" onPress={() => retry(n => n + 1)}>{t.retry}</Button></div>
        : !data ? <div className="fms-share-report-state" role="status"><Spinner size="sm" aria-label={t.loading}/></div>
        : <>
          <Tabs.Panel id="overview" className="fms-share-report-panel">
            <section className="fms-share-report-card fms-share-trend">
              <header className="fms-share-trend-header">
                <div>
                  <h3>{t.visits}{activePoint ? ' · ' + rangeDate.format(utcDay(activePoint.date)) : ''}</h3>
                  <strong className="fms-share-headline">{number.format(activePoint?.visits ?? data.visits)}</strong>
                </div>
                {hasDuration && <div className="fms-share-chart-modes" role="group" aria-label={t.analyticsChartMode}>
                  <Button size="sm" variant="ghost" aria-pressed={mode === 'area'} onPress={() => {setMode('area'); setActiveDay(null);}}>{t.visitTrend}</Button>
                  <Button size="sm" variant="ghost" aria-pressed={mode === 'combo'} onPress={() => {setMode('combo'); setActiveDay(null);}}>{t.analyticsEngagement}</Button>
                </div>}
              </header>
              <ShareChart key={chartMode} points={data.points} locale={locale} label={t.visits} durationLabel={t.avgDuration}
                mode={chartMode} onActiveDay={setActiveDay} errorLabel={t.chartUnavailable}/>
              <div className="fms-share-metrics">
                <div><span><i data-series="visits" aria-hidden="true"/>{t.visits}</span><strong>{number.format(activePoint?.visits ?? data.visits)}</strong></div>
                <div><span><i data-series="duration" aria-hidden="true"/>{t.avgDuration}</span><strong>{durationText(activePoint ? activePoint.averageSeconds : data.averageSeconds)}</strong></div>
                <div><span><i aria-hidden="true"/>{t.lastVisited}</span><strong>{data.lastVisitedAt
                  ? <time dateTime={data.lastVisitedAt} title={fullDate(data.lastVisitedAt)}>{shortDate.format(new Date(data.lastVisitedAt))}</time> : '—'}</strong></div>
              </div>
            </section>
            <section className="fms-share-report-card fms-share-location-card">
              <header><h3>{t.locations}</h3><span>{t.visits}</span></header>
              {data.countries.length === 0 ? <p className="fms-share-no-location">{t.noLocation}</p> :
                <ol className="fms-share-regions">{data.countries.map(row => {
                  const name = regions.of(row.country) ?? row.country;
                  const Flag = flags?.[row.country as keyof FlagModule['Flags']];
                  return <li key={row.country}>
                    <span className="fms-share-country-bar" aria-hidden="true" style={{inlineSize: (100 * row.visits / maxCountryVisits) + '%'}}/>
                    <span>{Flag ? <Flag aria-hidden="true" className="fms-share-country-flag"/> : <Icon icon={Globe02Icon} size={14}/>} {name}</span>
                    <strong>{number.format(row.visits)}</strong>
                  </li>;
                })}</ol>}
            </section>
          </Tabs.Panel>
          <Tabs.Panel id="daily" className="fms-share-report-panel">
            <DailyVisits points={data.points} locale={locale} page={page} onPageChange={setPage}/>
          </Tabs.Panel>
        </>}
    </Tabs>
  </div>;
}

function DailyVisits({points, locale, page, onPageChange}: {
  points: ShareAnalytics['points']; locale: string; page: number; onPageChange: (page: number) => void;
}) {
  const t = useManagementMessages();
  const [selectedDay, setSelectedDay] = useState<string | null>(null);
  const number = new Intl.NumberFormat(locale);
  const rangeDate = new Intl.DateTimeFormat(locale, {month: 'short', day: 'numeric', timeZone: 'UTC'});
  const weekday = new Intl.DateTimeFormat(locale, {weekday: 'short', timeZone: 'UTC'});
  const fullDate = new Intl.DateTimeFormat(locale, {dateStyle: 'full', timeZone: 'UTC'});
  const pageCount = Math.max(1, Math.ceil(points.length / daysPerPage));
  const end = points.length - (page - 1) * daysPerPage;
  const week = points.slice(Math.max(0, end - daysPerPage), end);
  const first = week[0];
  const last = week.at(-1);
  const total = week.reduce((sum, day) => sum + day.visits, 0);
  const maximum = Math.max(1, ...points.map(day => day.visits));

  function changePage(next: number) {
    setSelectedDay(null);
    onPageChange(next);
  }

  return <section className="fms-share-report-card fms-share-daily" aria-label={t.dailyVisits}>
    <header className="fms-share-daily-header">
      <div aria-live="polite">
        <h3>{t.dailyVisits}</h3>
        <div className="fms-share-daily-total">
          <strong className="fms-share-headline">{number.format(total)}</strong>
          <span>{t.weekTotal}</span>
        </div>
      </div>
      {first && last && <div className="fms-share-week-nav" role="group" aria-label={t.period}>
        <Button isIconOnly size="sm" variant="ghost" aria-label={t.previousWeek}
          isDisabled={page === pageCount} onPress={() => changePage(page + 1)}>
          <Icon icon={ArrowLeft01Icon} size={16}/>
        </Button>
        <span aria-live="polite">{rangeDate.formatRange(utcDay(first.date), utcDay(last.date))}</span>
        <Button isIconOnly size="sm" variant="ghost" aria-label={t.nextWeek}
          isDisabled={page === 1} onPress={() => changePage(page - 1)}>
          <Icon icon={ArrowRight01Icon} size={16}/>
        </Button>
      </div>}
    </header>
    {week.length > 0 && <ol className="fms-share-day-bars">
      {week.map(day => {
        const date = utcDay(day.date);
        const count = number.format(day.visits);
        const selected = selectedDay === day.date;
        return <li key={day.date}>
          <Button variant="ghost" className="fms-share-day" aria-pressed={selected}
            aria-label={`${fullDate.format(date)} · ${t.visits} ${count}`}
            onPress={() => setSelectedDay(selected ? null : day.date)}>
            <span className="fms-share-day-track" aria-hidden="true">
              {day.visits > 0 && <span className="fms-share-day-fill" style={{blockSize: `${100 * day.visits / maximum}%`}}/>}
              <span className="fms-share-day-value">{count}</span>
            </span>
            <time dateTime={day.date}>{weekday.format(date)}</time>
          </Button>
        </li>;
      })}
    </ol>}
    {total === 0 && <p className="fms-share-daily-empty">{t.analyticsEmpty}</p>}
  </section>;
}

function ShareChart({points, locale, label, durationLabel, mode, onActiveDay, errorLabel}: {
  points: ShareAnalytics['points']; locale: string; label: string; durationLabel: string;
  mode: 'area' | 'combo'; onActiveDay: (date: string | null) => void; errorLabel: string;
}) {
  const host = useRef<HTMLDivElement>(null);
  const [error, setError] = useState(false);
  useEffect(() => {
    const element = host.current;
    if (!element) return;
    let disposed = false;
    let chart: ReturnType<ChartModule['mountShareChart']> | undefined;
    setError(false);
    chartModule ??= import(new URL('./api-usage-chart.js', import.meta.url).href).catch(reason => {
      chartModule = undefined;
      throw reason;
    });
    void chartModule.then(module => {
      if (!disposed) chart = module.mountShareChart(element, {points, locale, label, durationLabel, mode, onActiveDay});
    }).catch(() => {if (!disposed) setError(true);});
    return () => {disposed = true; chart?.destroy();};
  }, [points, locale, label, durationLabel, mode, onActiveDay]);

  return <div className="fms-share-chart-frame"><div className="fms-share-chart" ref={host}/>
    {error && <p role="status" className="fms-share-report-state">{errorLabel}</p>}
  </div>;
}
