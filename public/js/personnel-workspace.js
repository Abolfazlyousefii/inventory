(() => {
    'use strict';
    const root=document.querySelector('[data-personnel-workspace]');
    if(!root)return;
    const filters=root.querySelector('#staffFilters'),dialog=root.querySelector('#staffDialog'),host=root.querySelector('#staffEditorHost'),feedback=root.querySelector('#staffFeedback');
    let treeRequest,editorRequest,timer,busy=false,editorUrl='',activeTab='profile';
    const collapsed=new Set();
    let initialProfile='',initialRoles='',initialPermissions='';
    const headers={'X-Requested-With':'XMLHttpRequest'};
    const values=(name)=>JSON.stringify([...host.querySelectorAll(`input[name="${name}[]"]:checked`)].map(x=>x.value).sort());
    const profileValue=()=>JSON.stringify([...new FormData(host.querySelector('section[data-staff-section="profile"] form'))].filter(([key])=>!['_token','_method','organization_version'].includes(key)));
    const dirty=()=>!!host.querySelector('[data-staff-editor]') && (profileValue()!==initialProfile || values('roles')!==initialRoles || values('direct_permissions')!==initialPermissions);
    function applyTree(){
        const rows=[...root.querySelectorAll('[data-staff-node]')],byId=new Map(rows.map(row=>[row.dataset.staffNode,row]));
        rows.forEach(row=>{let parent=row.dataset.parent,hide=false;const visited=new Set();while(parent && byId.has(parent) && !visited.has(parent)){visited.add(parent);if(collapsed.has(parent)){hide=true;break;}parent=byId.get(parent).dataset.parent;}row.hidden=hide;});
        root.querySelectorAll('[data-toggle-node]').forEach(button=>{const open=!collapsed.has(button.dataset.toggleNode);button.setAttribute('aria-expanded',String(open));button.textContent=open?'−':'+';});
    }
    function initTree(){
        collapsed.clear();if(root.querySelector('#staffTree').dataset.filtered!=='1')root.querySelectorAll('[data-toggle-node]').forEach(button=>collapsed.add(button.dataset.toggleNode));applyTree();
    }
    async function loadTree(preserve=false){
        treeRequest?.abort();const request=treeRequest=new AbortController();
        const url=new URL(filters.action);new FormData(filters).forEach((value,key)=>{if(value!=='')url.searchParams.set(key,value);});
        root.querySelector('#staffTree').setAttribute('aria-busy','true');feedback.textContent='';
        try{const response=await fetch(url,{headers,signal:request.signal});if(!response.ok||response.redirected)throw new Error('دریافت فهرست انجام نشد؛ دوباره تلاش کنید یا صفحه را تازه کنید.');const text=await response.text();if(request.signal.aborted)return;const doc=new DOMParser().parseFromString(text,'text/html'),tree=doc.querySelector('#staffTree');if(!tree)throw new Error('پاسخ فهرست معتبر نیست.');root.querySelector('#staffTree').replaceWith(tree);history.replaceState(null,'',url);root.querySelector('[data-staff-count]').textContent=tree.dataset.count+' نفر در ساختار';preserve?applyTree():initTree();}
        catch(error){if(error.name!=='AbortError')feedback.textContent=error.message;}
        finally{if(treeRequest===request)root.querySelector('#staffTree').removeAttribute('aria-busy');}
    }
    function selectTab(tab){activeTab=tab;host.querySelectorAll('[data-staff-section]').forEach(section=>section.hidden=section.dataset.staffSection!==tab);host.querySelectorAll('[data-staff-tab]').forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.staffTab===tab)));host.querySelector('[data-access-footer]').hidden=tab==='profile';}
    async function loadEditor(url,tab='profile'){
        editorRequest?.abort();const request=editorRequest=new AbortController();editorUrl=url;host.textContent='در حال دریافت اطلاعات…';
        try{const response=await fetch(url,{headers,signal:request.signal});if(!response.ok||response.redirected)throw new Error('دریافت اطلاعات انجام نشد یا دسترسی شما تغییر کرده است.');const html=await response.text();if(request.signal.aborted)return;host.innerHTML=html;if(!host.querySelector('[data-staff-editor]'))return;initialProfile=profileValue();initialRoles=values('roles');initialPermissions=values('direct_permissions');selectTab(tab);}
        catch(error){if(error.name!=='AbortError')host.textContent=error.message;}
    }
    function closeEditor(){if(busy)return;if(dirty()&&!confirm('تغییرات ذخیره نشده کنار گذاشته شود؟'))return;editorRequest?.abort();dialog.close();host.textContent='';}
    filters.addEventListener('submit',event=>{event.preventDefault();clearTimeout(timer);loadTree();});
    filters.addEventListener('input',event=>{if(event.target.name==='q'){clearTimeout(timer);timer=setTimeout(loadTree,280);}});
    filters.addEventListener('change',event=>{if(event.target.name!=='q'){clearTimeout(timer);loadTree();}});
    root.addEventListener('click',event=>{
        const toggle=event.target.closest('[data-toggle-node]');if(toggle){const id=toggle.dataset.toggleNode;collapsed.has(id)?collapsed.delete(id):collapsed.add(id);applyTree();}
        if(event.target.closest('[data-expand-all]')){collapsed.clear();applyTree();}
        if(event.target.closest('[data-collapse-all]')){root.querySelectorAll('[data-toggle-node]').forEach(button=>collapsed.add(button.dataset.toggleNode));applyTree();}
        if(event.target.closest('[data-reset-filters]')){filters.querySelector('[name=q]').value='';filters.querySelectorAll('select').forEach(select=>select.value='');filters.querySelectorAll('input[type=checkbox]').forEach(input=>input.checked=false);clearTimeout(timer);loadTree();}
        const edit=event.target.closest('[data-edit-staff]');if(edit){root.querySelector('#staffDialogTitle').textContent=edit.dataset.personName;dialog.showModal();loadEditor(edit.dataset.editorUrl);}
        if(event.target.closest('[data-close-editor]'))closeEditor();
        const tab=event.target.closest('[data-staff-tab]');if(tab){
            const next=tab.dataset.staffTab;
            const crossingForm=(activeTab==='profile')!==(next==='profile');
            if(crossingForm && dirty()){
                if(confirm('تغییرات این بخش هنوز ذخیره نشده است. برای رفتن به بخش دیگر کنار گذاشته شود؟'))loadEditor(editorUrl,next);
            }else selectTab(next);
        }
    });
    dialog.addEventListener('cancel',event=>{event.preventDefault();closeEditor();});
    host.addEventListener('input',event=>{
        const permissionSearch=event.target.matches('[data-permission-search]'),roleSearch=event.target.matches('[data-role-search]');
        if(!permissionSearch&&!roleSearch)return;const term=event.target.value.trim().toLocaleLowerCase();
        host.querySelectorAll(permissionSearch?'[data-permission-choice]':'[data-role-choice]').forEach(choice=>{choice.hidden=!!term && !(choice.textContent+' '+(choice.closest('details')?.querySelector('summary')?.textContent||'')).toLocaleLowerCase().includes(term);});
        host.querySelectorAll(permissionSearch?'[data-staff-section="permissions"] details':'[data-staff-section="roles"] details').forEach(group=>{group.hidden=!!term && ![...group.querySelectorAll(permissionSearch?'[data-permission-choice]':'[data-role-choice]')].some(choice=>!choice.hidden);group.open=!!term;});
    });
    host.addEventListener('change',event=>{
        if(event.target.matches('input[name="direct_permissions[]"]')&&event.target.checked){
            const inputs=new Map([...host.querySelectorAll('input[name="direct_permissions[]"]')].map(input=>[input.value,input])),seen=new Set();
            const add=input=>{if(seen.has(input.value))return;seen.add(input.value);for(const dependency of JSON.parse(input.dataset.dependencies||'[]')){const next=inputs.get(dependency);if(next&&!next.disabled){next.checked=true;add(next);}}};add(event.target);
        }
        const form=host.querySelector('[data-access-editor]');if(!form)return;
        form.elements.roles_changed.value=values('roles')!==initialRoles?'1':'0';form.elements.direct_permissions_changed.value=values('direct_permissions')!==initialPermissions?'1':'0';host.querySelector('[data-access-change-note]').textContent=form.elements.roles_changed.value==='1'||form.elements.direct_permissions_changed.value==='1'?'تغییرات ذخیره نشده':'بدون تغییر';
    });
    host.addEventListener('submit',async event=>{
        const form=event.target;if(!(form instanceof HTMLFormElement))return;event.preventDefault();if(busy)return;
        const notice=host.querySelector('.pw-editor-feedback');notice.textContent='در حال ذخیره…';busy=true;const buttons=[...host.querySelectorAll('button')];buttons.forEach(button=>button.disabled=true);
        try{const response=await fetch(form.action,{method:'POST',headers:{...headers,Accept:'application/json'},body:new FormData(form)});const data=await response.json();if(!response.ok)throw new Error(Object.values(data.errors||{}).flat().join('\n')||data.message||'ذخیره انجام نشد.');if(data.ok!==true)throw new Error('پاسخ ذخیره معتبر نیست.');await loadEditor(editorUrl,activeTab);const message=host.querySelector('.pw-editor-feedback');if(message)message.textContent=data.message;await loadTree(true);}
        catch(error){notice.textContent=error.message||'اتصال برقرار نشد؛ تغییرات شما در فرم باقی مانده است.';}
        finally{busy=false;buttons.forEach(button=>button.disabled=false);}
    });
    window.addEventListener('beforeunload',event=>{if(dialog.open&&dirty()){event.preventDefault();event.returnValue='';}});
    initTree();
})();
