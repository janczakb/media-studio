import type {BrowseChoice,BrowseExtensions} from './contracts';

const EMPTY:BrowseExtensions = Object.freeze({});
const RESERVED_SORTS = new Set(['name','updated','size','type']);
const IDENTIFIER = /^[a-z][a-z0-9_-]{0,63}$/;

export function validateBrowseExtensions(value:BrowseExtensions|undefined):BrowseExtensions {
  if(value===undefined)return EMPTY;
  const validateChoices=(choices:BrowseChoice[],maximum:number,keys:boolean)=>{
    if(!Array.isArray(choices)||choices.length>maximum)throw new Error('Studio browse configuration exceeds its option limit.');
    const seen=new Set<string>();
    for(const item of choices){
      if(!item||typeof item.id!=='string'||!item.id||item.id.length>191||(keys&&!IDENTIFIER.test(item.id))||
        typeof item.label!=='string'||!item.label.trim()||item.label.length>160||seen.has(item.id)){
        throw new Error('Studio browse configuration contains an invalid or duplicate option.');
      }
      seen.add(item.id);
    }
  };
  validateChoices(value.filters??[],8,true);
  validateChoices(value.sorters??[],16,true);
  for(const filter of value.filters??[])validateChoices(filter.options,100,false);
  if(value.sorters?.some(sort=>RESERVED_SORTS.has(sort.id)))throw new Error('A custom Studio sorter cannot replace a built-in sorter.');
  return value;
}
