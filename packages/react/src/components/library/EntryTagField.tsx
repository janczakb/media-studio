import {Button, ComboBox, EmptyState, Input, ListBox, Spinner} from '@heroui/react';
import {PlusSignIcon} from '@hugeicons/core-free-icons';
import {useEffect, useState} from 'react';
import type {BrowseChoice, EntryTag, EntryTagInput, StudioTransport} from '../../contracts';
import {Icon, type Messages} from '../../ui';

const createTag = 'create-tag';

export function EntryTagField({workspace, source, tags, value, onChange, onAdd, busy, portal, t}: {
  workspace: string;
  source: StudioTransport['browseChoices'];
  tags: EntryTag[];
  value: string;
  onChange: (value: string) => void;
  onAdd: (tag: EntryTagInput) => void;
  busy: boolean;
  portal: HTMLDivElement;
  t: Messages;
}) {
  const [result, setResult] = useState<{workspace: string; query: string; items: BrowseChoice[]; failed?: true} | null>(null);
  const name = value.trim();
  const query = name.toLowerCase();
  const searchable = !!source && query.length > 0 && query.length <= 100;
  const current = searchable && result?.workspace === workspace && result.query === query ? result : null;
  const loading = searchable && current === null;
  const suggestions = current?.items.filter(item => !tags.some(tag => tag.id === item.id)) ?? [];
  const exact = suggestions.find(item => item.label.toLowerCase() === query);
  const canCreate = name !== '' && !tags.some(tag => tag.label.toLowerCase() === query) && !exact;
  const options = canCreate && !loading ? [...suggestions, {id: createTag, label: name}] : suggestions;

  useEffect(() => {
    if (!source || !searchable) return;
    const controller = new AbortController();
    const timer = setTimeout(() => {
      void source(workspace, 'tags', {search: query}, controller.signal).then(({items}) => {
        if (!controller.signal.aborted) setResult({workspace, query, items});
      }).catch(() => {
        if (!controller.signal.aborted) setResult({workspace, query, items: [], failed: true});
      });
    }, 160);
    return () => {clearTimeout(timer); controller.abort();};
  }, [source, searchable, query, workspace]);

  function submit() {
    if (busy || loading) return;
    if (exact) onAdd({id: exact.id});
    else if (canCreate) onAdd({name});
  }

  const input = <Input className="fms-inspector-tag-input" aria-label={t.addTag} placeholder={t.addTag}
    variant="secondary" maxLength={120} readOnly={busy}/>;
  return <form className="fms-inspector-tag-form" onSubmit={event => {event.preventDefault(); submit();}}
    onKeyDownCapture={event => {
      const target = event.target;
      if (event.key !== 'Enter' || event.nativeEvent.isComposing || !(target instanceof HTMLInputElement)
        || target.getAttribute('role') !== 'combobox' || target.getAttribute('aria-activedescendant')) return;
      event.preventDefault();
      event.stopPropagation();
      submit();
    }}>
    {source ? <ComboBox aria-label={t.addTag} variant="secondary" menuTrigger="input" allowsCustomValue
      allowsEmptyCollection inputValue={value} onInputChange={onChange} selectedKey={null}
      items={options} onSelectionChange={key => {
        if (busy || key === null) return;
        if (key === createTag && canCreate) onAdd({name});
        else if (suggestions.some(item => item.id === key)) onAdd({id: String(key)});
      }}>
      {input}
      <ComboBox.Popover placement="bottom start" offset={4} className="fms-inspector-tag-popover"
        UNSTABLE_portalContainer={portal}>
        {current?.failed && <div className="fms-inspector-tag-status" role="status">{t.tagSuggestionsUnavailable}</div>}
        <ListBox<BrowseChoice> renderEmptyState={() => (
          <EmptyState className="fms-inspector-tag-status">
            {loading ? <div className="fms-inspector-tag-loading" role="status">
              <Spinner size="sm" aria-hidden="true"/>
              <span>{t.loadingTagSuggestions}</span>
            </div> : name ? t.noTags : t.addTag}
          </EmptyState>
        )}>
          {item => <ListBox.Item id={item.id} textValue={item.label}>
            {item.id === createTag ? <><Icon icon={PlusSignIcon} size={15}/>{t.createFileTag.replace('{tag}', item.label)}</> : item.label}
          </ListBox.Item>}
        </ListBox>
      </ComboBox.Popover>
    </ComboBox> : <Input className="fms-inspector-tag-input" aria-label={t.addTag} placeholder={t.addTag}
      variant="secondary" maxLength={120} readOnly={busy} value={value} onChange={event => onChange(event.target.value)}/>}
    <Button size="sm" type="submit" isIconOnly variant="secondary" isDisabled={busy || loading || (!exact && !canCreate)}
      aria-label={t.addFileTag}><Icon icon={PlusSignIcon} size={16}/></Button>
  </form>;
}
