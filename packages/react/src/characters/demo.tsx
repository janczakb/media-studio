import {createRoot} from 'react-dom/client';
import {useState} from 'react';
import {CharacterCreator} from './CharacterCreator';
import {defaultCharacter,normalizeCharacter} from './model';
function Demo(){
 const [value,setValue]=useState(()=>{try{return normalizeCharacter(JSON.parse(localStorage.getItem('flex-character-v1')??'null'));}catch{return defaultCharacter;}});
 return <CharacterCreator value={value} onChange={setValue} onSave={c=>localStorage.setItem('flex-character-v1',JSON.stringify(c))}/>;
}
const element=document.getElementById('characters');if(element)createRoot(element).render(<Demo/>);
