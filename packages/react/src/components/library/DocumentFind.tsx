import {Button,InputGroup,SearchField,Tooltip} from '@heroui/react';
import {ArrowUp01Icon,ArrowDown01Icon,Search01Icon} from '@hugeicons/core-free-icons';
import {Icon,type Messages} from '../../ui';
import type {OfficeFindState,OfficeFindResult} from '../../office-preview-contract';

export function DocumentFind({state,result,onChange,onClose,t,portal}: {
  state:OfficeFindState;result:OfficeFindResult;onChange:(state:OfficeFindState)=>void;
  onClose:()=>void;t:Messages;portal:HTMLDivElement;
}) {
  const navigate=(direction:1|-1)=>onChange({...state,step:state.step+1,direction});
  const tip=(label:string,child:React.ReactNode)=><Tooltip delay={300}>{child}<Tooltip.Content UNSTABLE_portalContainer={portal}>{label}</Tooltip.Content></Tooltip>;
  return <div className="fms-document-find" role="search" aria-label={t.findDocument}
    onKeyDown={event=>{event.stopPropagation();if(event.key==='Escape'){event.preventDefault();onClose();}else if(event.key==='Enter'){event.preventDefault();navigate(event.shiftKey?-1:1);}}}>
    <SearchField className="fms-document-find-field" aria-label={t.findDocument} fullWidth variant="secondary"
      value={state.query} onChange={query=>onChange({...state,query})}>
      <InputGroup variant="secondary">
        <InputGroup.Prefix><Icon icon={Search01Icon} size={20}/></InputGroup.Prefix>
        <InputGroup.Input type="text" role="searchbox" autoFocus placeholder={t.findDocument}/>
        <InputGroup.Suffix>
          <output className="fms-document-find-count" aria-live="polite">{result.index} {t.findOf} {result.total}</output>
          {state.query&&<SearchField.ClearButton aria-label={t.clearSearch}/>}
        </InputGroup.Suffix>
      </InputGroup>
    </SearchField>
    {tip(t.findNext,<Button className="fms-document-find-navigation" size="sm" variant="ghost" isIconOnly aria-label={t.findNext} isDisabled={!result.total} onPress={()=>navigate(1)}><Icon icon={ArrowDown01Icon} size={24}/></Button>)}
    {tip(t.findPrevious,<Button className="fms-document-find-navigation" size="sm" variant="ghost" isIconOnly aria-label={t.findPrevious} isDisabled={!result.total} onPress={()=>navigate(-1)}><Icon icon={ArrowUp01Icon} size={24}/></Button>)}
  </div>;
}
