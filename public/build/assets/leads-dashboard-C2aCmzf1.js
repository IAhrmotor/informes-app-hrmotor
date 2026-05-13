var e=new Intl.NumberFormat(`es-ES`),t={selectedPortal:`Web`,portals:[]};document.addEventListener(`DOMContentLoaded`,async()=>{n(),r(),await i(),await a(),await o(),await f(t.selectedPortal),await p(),await m(),await h(),await g()});function n(){document.querySelectorAll(`.main-tab`).forEach(e=>{e.addEventListener(`click`,()=>{let t=e.dataset.panel;document.querySelectorAll(`.main-tab`).forEach(e=>e.classList.remove(`active`)),document.querySelectorAll(`.tab-panel`).forEach(e=>e.classList.remove(`active`)),e.classList.add(`active`),document.getElementById(t)?.classList.add(`active`)})})}function r(){[`period`,`expositionMode`,`channel`,`portal`].forEach(e=>{let t=document.getElementById(e);t&&t.addEventListener(`change`,async()=>{await w()})})}async function i(){let t=await _(`/informes/leads/data/resumen`);document.getElementById(`kpiTotal`).textContent=e.format(t.total_leads??0),document.getElementById(`kpiCalls`).textContent=e.format(t.llamadas??0),document.getElementById(`kpiForms`).textContent=e.format(t.formularios??0),document.getElementById(`kpiPending`).textContent=e.format(t.pendientes_clasificar??0)}async function a(){let e=await _(`/informes/leads/data/kpis`),t=document.getElementById(`kpiRows`);t.innerHTML=``,e.items.forEach(e=>{t.insertAdjacentHTML(`beforeend`,`
            <tr>
                <td><strong>${T(e.metrica)}</strong></td>
                <td class="num">${y(e.con_exposicion)}</td>
                <td class="num">${y(e.sin_exposicion)}</td>
                <td class="num">${y(e.diferencia)}</td>
            </tr>
        `)})}async function o(){t.portals=(await _(`/informes/leads/data/portales`)).items,s(),c(),l(),u()}function s(){let e=document.getElementById(`portal`);if(!e)return;let n=e.value;e.innerHTML=`<option value="">Todos</option>`,t.portals.forEach(t=>{let n=document.createElement(`option`);n.value=t.portal,n.textContent=t.portal,e.appendChild(n)}),e.value=n}function c(){let n=document.getElementById(`portalBars`),r=Math.max(...t.portals.map(e=>e.total),1);n.innerHTML=``,t.portals.forEach(t=>{let i=t.total?t.llamadas/t.total*100:0,a=t.total?t.formularios/t.total*100:0,o=t.total/r*100;n.insertAdjacentHTML(`beforeend`,`
            <div class="bar-row">
                <div class="portal-name" title="${T(t.portal)}">${T(t.portal)}</div>
                <div class="stack" title="${e.format(t.llamadas)} llamadas · ${e.format(t.formularios)} formularios" style="width:${Math.max(o,4)}%">
                    <div class="seg-call" style="width:${i}%"></div>
                    <div class="seg-form" style="width:${a}%"></div>
                </div>
                <div class="total">${e.format(t.total)}</div>
            </div>
        `)})}function l(){let n=document.getElementById(`portalList`);n.innerHTML=``,t.portals.forEach(r=>{let i=document.createElement(`button`);i.type=`button`,i.className=`portal-btn${r.portal===t.selectedPortal?` active`:``}`,i.addEventListener(`click`,()=>d(r.portal)),i.innerHTML=`
            <div class="portal-row">
                <span>${T(r.portal)}</span>
                <span>${e.format(r.total)}</span>
            </div>
            <div class="portal-sub">
                <span>${e.format(r.llamadas)} llamadas</span>
                <span>${e.format(r.formularios)} formularios</span>
            </div>
        `,n.appendChild(i)})}function u(){let n=document.getElementById(`portalRows`);n.innerHTML=``,t.portals.forEach(t=>{let r=v(t.llamadas,t.total),i=v(t.formularios,t.total);n.insertAdjacentHTML(`beforeend`,`
            <tr>
                <td><strong>${T(t.portal)}</strong></td>
                <td class="num">${e.format(t.llamadas)}</td>
                <td class="num">${e.format(t.formularios)}</td>
                <td class="num"><strong>${e.format(t.total)}</strong></td>
                <td class="num">${r}</td>
                <td class="num">${i}</td>
                <td class="num">${e.format(t.convertidos??0)}</td>
                <td class="num">${t.conversion_pct??`-`}</td>
            </tr>
        `)})}async function d(e){t.selectedPortal=e,l(),await f(e)}async function f(t){let n=await _(`/informes/leads/data/portal-detalle?${new URLSearchParams({portal:t}).toString()}`);document.getElementById(`selectedPortalBadge`).textContent=n.portal;let r=document.getElementById(`detailRows`);r.innerHTML=``,n.items.forEach(t=>{let n=t.tipo===`Delegación`?`type-pill`:t.tipo===`Grupo`?`type-pill group`:`type-pill pending`;r.insertAdjacentHTML(`beforeend`,`
            <tr>
                <td><strong>${T(t.delegacion)}</strong></td>
                <td><span class="${n}">${T(t.tipo)}</span></td>
                <td>${T(t.grupo_comercial??`-`)}</td>
                <td class="num">${e.format(t.llamadas)}</td>
                <td class="num">${e.format(t.formularios)}</td>
                <td class="num"><strong>${e.format(t.total)}</strong></td>
            </tr>
        `)})}async function p(){let t=await _(`/informes/leads/data/delegaciones?${C().toString()}`),n=document.getElementById(`delegationRows`);if(n){if(n.innerHTML=``,!t.items.length){n.insertAdjacentHTML(`beforeend`,`
            <tr>
                <td colspan="11">No hay datos de delegaciones para los filtros seleccionados.</td>
            </tr>
        `);return}t.items.forEach(t=>{let r=t.tipo===`Delegación`?`type-pill`:t.tipo===`Grupo`?`type-pill group`:`type-pill pending`;n.insertAdjacentHTML(`beforeend`,`
            <tr>
                <td><strong>${T(t.delegacion)}</strong></td>
                <td><span class="${r}">${T(t.tipo)}</span></td>
                <td>${T(t.grupo_comercial??`-`)}</td>
                <td class="num"><strong>${e.format(t.total)}</strong></td>
                <td class="num">${e.format(t.llamadas)}</td>
                <td class="num">${e.format(t.formularios)}</td>
                <td class="num">${e.format(t.convertidos)}</td>
                <td class="num">${b(t.conversion_pct)}</td>
                <td class="num">${e.format(t.descartados)}</td>
                <td class="num">${b(t.descarte_pct)}</td>
                <td class="num">${e.format(t.incidencias)}</td>
            </tr>
        `)})}}async function m(){let t=await _(`/informes/leads/data/comerciales?${C().toString()}`),n=document.getElementById(`commercialRows`);if(n){if(n.innerHTML=``,!t.items.length){n.insertAdjacentHTML(`beforeend`,`
            <tr>
                <td colspan="12">No hay datos de comerciales para los filtros seleccionados.</td>
            </tr>
        `);return}t.items.forEach(t=>{n.insertAdjacentHTML(`beforeend`,`
            <tr>
                <td><strong>${T(t.comercial)}</strong></td>
                <td class="num"><strong>${e.format(t.total)}</strong></td>
                <td class="num">${e.format(t.llamadas)}</td>
                <td class="num">${e.format(t.formularios)}</td>
                <td class="num">${e.format(t.convertidos)}</td>
                <td class="num">${b(t.conversion_pct)}</td>
                <td class="num">${e.format(t.descartados)}</td>
                <td class="num">${b(t.descarte_pct)}</td>
                <td class="num">${e.format(t.potenciales)}</td>
                <td class="num">${e.format(t.sin_task_event)}</td>
                <td class="num">${b(t.cobertura_task_event_pct)}</td>
                <td class="num">${e.format(t.sin_seguimiento_reciente)}</td>
            </tr>
        `)})}}async function h(){let e=await _(`/informes/leads/data/comparativa?${C().toString()}`),t=document.getElementById(`comparisonRows`),n=document.getElementById(`comparisonPeriodLabel`);if(t){if(t.innerHTML=``,n&&e.periodo_actual&&e.periodo_comparado&&(n.textContent=`Periodo actual ${e.periodo_actual.desde} a ${e.periodo_actual.hasta} · Comparado con ${e.periodo_comparado.desde} a ${e.periodo_comparado.hasta}`),!e.items.length){t.insertAdjacentHTML(`beforeend`,`
            <tr>
                <td colspan="4">No hay datos de comparativa para los filtros seleccionados.</td>
            </tr>
        `);return}e.items.forEach(e=>{t.insertAdjacentHTML(`beforeend`,`
            <tr>
                <td><strong>${T(e.metrica)}</strong></td>
                <td class="num">${x(e.periodo_actual,e.is_percentage)}</td>
                <td class="num">${x(e.periodo_comparado,e.is_percentage)}</td>
                <td class="num">${S(e.diferencia,e.is_percentage)}</td>
            </tr>
        `)})}}async function g(){let t=await _(`/informes/leads/data/calidad-dato`),n=document.getElementById(`qualityRows`);n.innerHTML=``,t.items.forEach(t=>{n.insertAdjacentHTML(`beforeend`,`
            <tr>
                <td><strong>${T(t.incidencia)}</strong></td>
                <td class="num">${e.format(t.registros)}</td>
                <td>${T(t.accion)}</td>
            </tr>
        `)})}async function _(e){let t=await fetch(e,{headers:{Accept:`application/json`}});if(!t.ok)throw Error(`Error cargando ${e}`);return t.json()}function v(e,t){return t?`${(e/t*100).toFixed(1)}%`:`-`}function y(t){return t==null?`-`:typeof t==`number`?e.format(t):T(String(t))}function b(e){return e==null?`-`:`${Number(e).toFixed(2)}%`}function x(t,n=!1){return t==null?`-`:n?`${Number(t).toFixed(2)}%`:e.format(Number(t))}function S(t,n=!1){if(t==null)return`-`;let r=Number(t),i=r>0?`+`:``;return n?`${i}${r.toFixed(2)} pp`:`${i}${e.format(r)}`}function C(){let e=new URLSearchParams,t=document.getElementById(`channel`)?.value,n=document.getElementById(`portal`)?.value,r=document.getElementById(`expositionMode`)?.value;return t&&e.set(`channel`,t),n&&e.set(`portal`,n),r&&e.set(`exposition_mode`,r),e}async function w(){await i(),await a(),await o(),await f(t.selectedPortal),await p(),await m(),await h(),await g()}function T(e){return String(e).replaceAll(`&`,`&amp;`).replaceAll(`<`,`&lt;`).replaceAll(`>`,`&gt;`).replaceAll(`"`,`&quot;`).replaceAll(`'`,`&#039;`)}