import {useEffect, useRef, useState} from 'react';
import type {StudioOptions, Workspace} from '../../contracts';
import {StudioHttpError} from '../../http';
import {errorText} from '../../ui';

/** The directory belongs to the instance, not to a page. Navigation never clears it. */
export function useWorkspaceDirectory({transport, workspaceId, workspaceIds}: StudioOptions, requested: string | null, attempt: number) {
  const [workspaces, setWorkspaces] = useState<Workspace[]>([]);
  const [multipleEnabled,setMultipleEnabled]=useState(true),[workspaceLimit,setWorkspaceLimit]=useState(10);
  const [workspaceIdentity,setWorkspaceIdentity]=useState(false);
  const [appearance,setAppearance]=useState<{primaryColor?:string}>({});
  const [viewer,setViewer]=useState<{name?:string|null;email?:string|null;avatarUrl?:string|null}>({});
  const [canCreate, setCanCreate] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const requestedRef = useRef(requested);
  requestedRef.current = requested;
  // A caller may construct a fresh but equivalent allowlist on each render.
  const scopeKey = JSON.stringify(workspaceIds ?? []);
  useEffect(() => {
    const controller = new AbortController();
    const scope = JSON.parse(scopeKey) as string[];
    setLoading(true); setAppearance({});setViewer({});setError(''); setWorkspaces([]); setCanCreate(false);
    const find = async (id: string) => {
      try {return await transport.workspace!(id, controller.signal);}
      catch (error) {
        if (error instanceof StudioHttpError && [403, 404].includes(error.status)) return null;
        throw error;
      }
    };
    void (async () => {
      const result = scope.length && transport.workspace
        ? {items: (await Promise.all(scope.slice(0, 20).map(find))).filter((item): item is Workspace => item !== null), canCreate: false}
        : workspaceId && transport.workspace
        ? {items: (await Promise.all([find(workspaceId)])).filter((item): item is Workspace => item !== null), canCreate: false}
        : await transport.workspaces(controller.signal);
      let items = result.items.filter(item => (!scope.length || scope.includes(item.id)) && (!workspaceId || workspaceId === item.id));
      const deepLink = workspaceId ?? requestedRef.current;
      if (deepLink && transport.workspace && !items.some(item => item.id === deepLink) && (!scope.length || scope.includes(deepLink)) && (!workspaceId || workspaceId === deepLink)) {
        const item = await find(deepLink);
        if (item) items = [...items, item];
      }
      if (!controller.signal.aborted) {setWorkspaces(items);setWorkspaceIdentity('workspaceIdentity' in result&&result.workspaceIdentity===true);setAppearance('appearance' in result?result.appearance??{}:{});setViewer('viewer' in result?result.viewer??{}:{});setMultipleEnabled('workspacesEnabled' in result?result.workspacesEnabled!==false:!workspaceId); setWorkspaceLimit('workspaceLimit' in result?result.workspaceLimit??10:10);setCanCreate(!workspaceId && ('workspaceCreationAllowed' in result?result.workspaceCreationAllowed??result.canCreate:result.canCreate));}
    })().catch(error => {if (!controller.signal.aborted) setError(errorText(error));})
      .finally(() => {if (!controller.signal.aborted) setLoading(false);});
    return () => controller.abort();
  }, [transport, workspaceId, scopeKey, attempt]);
  return {workspaceIdentity,appearance,viewer,workspaces, setWorkspaces,multipleEnabled, canCreate:canCreate&&workspaces.filter(w=>w.role==='owner').length<workspaceLimit, loading, error};
}
