var e=new Intl.NumberFormat(`es-ES`),t={selectedPortal:`Web`,portals:[],monthlyReportLoaded:!1};document.addEventListener(`DOMContentLoaded`,async()=>{n(),r(),await i(),await a(),await o(),await f(t.selectedPortal),await p(),await m(),await h(),await g()});function n(){document.querySelectorAll(`.main-tab`).forEach(e=>{e.addEventListener(`click`,()=>{let n=e.dataset.panel;document.querySelectorAll(`.main-tab`).forEach(e=>e.classList.remove(`active`)),document.querySelectorAll(`.tab-panel`).forEach(e=>e.classList.remove(`active`)),e.classList.add(`active`),document.getElementById(n)?.classList.add(`active`),n===`panel-informe-mensual`&&!t.monthlyReportLoaded&&T()})})}function r(){[`period`,`expositionMode`,`channel`,`portal`].forEach(e=>{let t=document.getElementById(e);t&&t.addEventListener(`change`,async()=>{await w()})})}async function i(){let t=await _(`/informes/leads/data/resumen`);document.getElementById(`kpiTotal`).textContent=e.format(t.total_leads??0),document.getElementById(`kpiCalls`).textContent=e.format(t.llamadas??0),document.getElementById(`kpiForms`).textContent=e.format(t.formularios??0),document.getElementById(`kpiPending`).textContent=e.format(t.pendientes_clasificar??0)}async function a(){let e=await _(`/informes/leads/data/kpis`),t=document.getElementById(`kpiRows`);t.innerHTML=``,e.items.forEach(e=>{t.insertAdjacentHTML(`beforeend`,`
            <tr>
                <td><strong>${H(e.metrica)}</strong></td>
                <td class="num">${y(e.con_exposicion)}</td>
                <td class="num">${y(e.sin_exposicion)}</td>
                <td class="num">${y(e.diferencia)}</td>
            </tr>
        `)})}async function o(){t.portals=(await _(`/informes/leads/data/portales`)).items,s(),c(),l(),u()}function s(){let e=document.getElementById(`portal`);if(!e)return;let n=e.value;e.innerHTML=`<option value="">Todos</option>`,t.portals.forEach(t=>{let n=document.createElement(`option`);n.value=t.portal,n.textContent=t.portal,e.appendChild(n)}),e.value=n}function c(){let n=document.getElementById(`portalBars`),r=Math.max(...t.portals.map(e=>e.total),1);n.innerHTML=``,t.portals.forEach(t=>{let i=t.total?t.llamadas/t.total*100:0,a=t.total?t.formularios/t.total*100:0,o=t.total/r*100;n.insertAdjacentHTML(`beforeend`,`
            <div class="bar-row">
                <div class="portal-name" title="${H(t.portal)}">${H(t.portal)}</div>
                <div class="stack" title="${e.format(t.llamadas)} llamadas · ${e.format(t.formularios)} formularios" style="width:${Math.max(o,4)}%">
                    <div class="seg-call" style="width:${i}%"></div>
                    <div class="seg-form" style="width:${a}%"></div>
                </div>
                <div class="total">${e.format(t.total)}</div>
            </div>
        `)})}function l(){let n=document.getElementById(`portalList`);n.innerHTML=``,t.portals.forEach(r=>{let i=document.createElement(`button`);i.type=`button`,i.className=`portal-btn${r.portal===t.selectedPortal?` active`:``}`,i.addEventListener(`click`,()=>d(r.portal)),i.innerHTML=`
            <div class="portal-row">
                <span>${H(r.portal)}</span>
                <span>${e.format(r.total)}</span>
            </div>
            <div class="portal-sub">
                <span>${e.format(r.llamadas)} llamadas</span>
                <span>${e.format(r.formularios)} formularios</span>
            </div>
        `,n.appendChild(i)})}function u(){let n=document.getElementById(`portalRows`);n.innerHTML=``,t.portals.forEach(t=>{let r=v(t.llamadas,t.total),i=v(t.formularios,t.total);n.insertAdjacentHTML(`beforeend`,`
            <tr>
                <td><strong>${H(t.portal)}</strong></td>
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
                <td><strong>${H(t.delegacion)}</strong></td>
                <td><span class="${n}">${H(t.tipo)}</span></td>
                <td>${H(t.grupo_comercial??`-`)}</td>
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
                <td><strong>${H(t.delegacion)}</strong></td>
                <td><span class="${r}">${H(t.tipo)}</span></td>
                <td>${H(t.grupo_comercial??`-`)}</td>
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
                <td><strong>${H(t.comercial)}</strong></td>
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
                <td><strong>${H(e.metrica)}</strong></td>
                <td class="num">${x(e.periodo_actual,e.is_percentage)}</td>
                <td class="num">${x(e.periodo_comparado,e.is_percentage)}</td>
                <td class="num">${S(e.diferencia,e.is_percentage)}</td>
            </tr>
        `)})}}async function g(){let t=await _(`/informes/leads/data/calidad-dato`),n=document.getElementById(`qualityRows`);n.innerHTML=``,t.items.forEach(t=>{n.insertAdjacentHTML(`beforeend`,`
            <tr>
                <td><strong>${H(t.incidencia)}</strong></td>
                <td class="num">${e.format(t.registros)}</td>
                <td>${H(t.accion)}</td>
            </tr>
        `)})}async function _(e){let t=await fetch(e,{headers:{Accept:`application/json`}});if(!t.ok)throw Error(`Error cargando ${e}`);return t.json()}function v(e,t){return t?`${(e/t*100).toFixed(1)}%`:`-`}function y(t){return t==null?`-`:typeof t==`number`?e.format(t):H(String(t))}function b(e){return e==null?`-`:`${Number(e).toFixed(2)}%`}function x(t,n=!1){return t==null?`-`:n?`${Number(t).toFixed(2)}%`:e.format(Number(t))}function S(t,n=!1){if(t==null)return`-`;let r=Number(t),i=r>0?`+`:``;return n?`${i}${r.toFixed(2)} pp`:`${i}${e.format(r)}`}function C(){let e=new URLSearchParams,t=document.getElementById(`channel`)?.value,n=document.getElementById(`portal`)?.value,r=document.getElementById(`expositionMode`)?.value;return t&&e.set(`channel`,t),n&&e.set(`portal`,n),r&&e.set(`exposition_mode`,r),e}async function w(){await i(),await a(),await o(),await f(t.selectedPortal),await p(),await m(),await h(),await g()}async function T(){let e=document.getElementById(`monthlyReportMessage`),n=document.getElementById(`monthlyReportContent`);if(!(!e||!n)){e.textContent=`Cargando informe mensual...`,e.classList.remove(`is-hidden`),n.classList.add(`is-hidden`);try{let r=await E(`/informes/leads/data/monthly-commercial/summary`);if(!r.ok){e.textContent=r.message||`No hay informe mensual generado todavia.`,t.monthlyReportLoaded=!0;return}let[i,a,o,s,c,l,u]=await Promise.all([E(`/informes/leads/data/monthly-commercial/kpis`),E(`/informes/leads/data/monthly-commercial/evolution`),E(`/informes/leads/data/monthly-commercial/commercial-pending`),E(`/informes/leads/data/monthly-commercial/commercial-performance`),E(`/informes/leads/data/monthly-commercial/portals`),E(`/informes/leads/data/monthly-commercial/delegations`),E(`/informes/leads/data/monthly-commercial/delegation-pending`)]);D(r),O(i.data||{}),k(a.data||{}),A((o.data||{}).items||[]),j((s.data||{}).items||[]),M((c.data||{}).items||[]),N((l.data||{}).items||[]),P((u.data||{}).items||[]),e.classList.add(`is-hidden`),n.classList.remove(`is-hidden`),t.monthlyReportLoaded=!0}catch(t){throw e.textContent=`No se pudo cargar el informe mensual.`,t}}}async function E(e){return _(e)}function D(e){let t=e.data||{},n=document.getElementById(`monthlyGeneratedAt`),r=document.getElementById(`monthlyPeriodLabel`),i=document.getElementById(`monthlyPriorityList`);n&&(n.textContent=e.generated_at?`Generado ${e.generated_at.slice(0,10)}`:`-`);let a=t.periodos_estandar?.periodo_actual,o=t.periodos_estandar?.periodo_anterior;if(r&&a&&o&&(r.textContent=`${V(a.inicio)} a ${V(a.fin)} frente a ${V(o.inicio)} a ${V(o.fin)}`),!i)return;let s=t.resumen_ejecutivo?.prioridades||[];if(i.innerHTML=``,!s.length){i.innerHTML=`<div class="priority-item">No hay prioridades generadas para este snapshot.</div>`;return}s.slice(0,3).forEach(e=>{i.insertAdjacentHTML(`beforeend`,`
            <article class="priority-item">
                <strong>${H(e.titulo||`-`)}</strong>
                <p>${H(e.sugerencia||`-`)}</p>
            </article>
        `)})}function O(e){let t=document.getElementById(`monthlyKpiCards`);if(!t)return;let n=[[`L`,`Leads en analisis`,I(e.leads_en_analisis),`Muestra del periodo`],[`%`,`Conversion sobre total`,L(e.conversion_sobre_total),`Convertidos / total`],[`D`,`Descarte sobre total`,L(e.descarte_sobre_total),`Descartados / total`],[`T`,`Potenciales sin Task/Event`,I(e.potenciales_sin_task_event_registrada),`Falta trazabilidad registrada`],[`H`,`Tiempo medio 1a Task/Event`,R(e.tiempo_medio_hasta_primera_task_event_horas),`Desde asignacion`],[`P90`,`P90 1a Task/Event`,R(e.tiempo_p90_primera_task_event_horas),`Desde asignacion`],[`TE`,`Con primera Task/Event`,I(e.con_primera_task_event_registrada),`Leads asignados`],[`1h`,`1a gestion <1h con Task/Event`,L(e.primera_gestion_menos_1h_entre_leads_con_task_event),`Sobre leads con actividad`],[`A`,`1a gestion <1h asignados`,L(e.primera_gestion_menos_1h_sobre_leads_asignados),`Sobre leads asignados`]];t.innerHTML=``,n.forEach(([e,n,r,i])=>{t.insertAdjacentHTML(`beforeend`,`
            <div class="card kpi monthly-kpi">
                <div class="ico">${H(e)}</div>
                <div>
                    <div class="kpi-label">${H(n)}</div>
                    <div class="kpi-value">${H(r)}</div>
                    <div class="kpi-hint">${H(i)}</div>
                </div>
            </div>
        `)})}function k(e){let t=e.items||[],n=document.getElementById(`monthlyEvolutionRows`);if(n){if(n.innerHTML=``,!t.length){n.innerHTML=`<tr><td colspan="4">No hay datos de evolucion en el snapshot.</td></tr>`;return}t.forEach(e=>{n.insertAdjacentHTML(`beforeend`,`
            <tr>
                <td><strong>${H(e.metrica)}</strong></td>
                <td class="num">${z(e.periodo_actual,e)}</td>
                <td class="num">${z(e.periodo_anterior,e)}</td>
                <td class="num">${B(e.diferencia,e)}</td>
            </tr>
        `)})}}function A(e){F(`monthlyCommercialPendingRows`,e,[[`comercial`,e=>e.comercial||`-`],[`potenciales`,e=>I(e.leads_potenciales),!0],[`sin_task`,e=>I(e.potenciales_sin_ninguna_task_event),!0],[`ultima`,e=>I(e.potenciales_con_ultima_task_mayor_3_dias),!0],[`seguimiento`,e=>I(e.potenciales_sin_seguimiento_mayor_3_dias),!0]],`No hay potenciales pendientes por comercial.`)}function j(e){F(`monthlyCommercialPerformanceRows`,e,[[`comercial`,e=>e.comercial||`-`],[`leads`,e=>I(e.leads_totales),!0],[`convertidos`,e=>I(e.leads_convertidos),!0],[`conversion`,e=>L(e.conversion_sobre_total),!0],[`descartados`,e=>I(e.leads_descartados),!0],[`descarte`,e=>L(e.descarte_sobre_total),!0],[`gestionados`,e=>I(e.leads_gestionados),!0],[`primera`,e=>I(e.leads_con_primera_actividad),!0],[`menos1h`,e=>L(e.ratio_respondidos_menos_1h_sobre_asignados),!0]],`No hay rendimiento por comercial en el snapshot.`)}function M(e){F(`monthlyPortalRows`,e,[[`portal`,e=>e.portal||`-`],[`leads`,e=>I(e.leads_totales),!0],[`convertidos`,e=>I(e.leads_convertidos),!0],[`conversion`,e=>L(e.conversion_sobre_total),!0],[`potenciales`,e=>I(e.leads_potenciales),!0],[`seguimiento`,e=>I(e.potenciales_sin_seguimiento_mayor_3_dias),!0],[`tiempo`,e=>R(e.tiempo_medio_respuesta_horas),!0]],`No hay datos de portales en el snapshot.`)}function N(e){F(`monthlyDelegationRows`,e,[[`delegacion`,e=>e.delegacion||`-`],[`leads`,e=>I(e.leads_totales),!0],[`convertidos`,e=>I(e.leads_convertidos),!0],[`conversion`,e=>L(e.conversion_sobre_total),!0],[`descartados`,e=>I(e.leads_descartados),!0],[`descarte`,e=>L(e.descarte_sobre_total),!0],[`p90`,e=>R(e.tiempo_p90_respuesta_horas),!0]],`No hay datos de delegaciones en el snapshot.`)}function P(e){F(`monthlyDelegationPendingRows`,e,[[`delegacion`,e=>e.delegacion||`-`],[`potenciales`,e=>I(e.leads_potenciales),!0],[`sin_task`,e=>I(e.potenciales_sin_ninguna_task_event),!0],[`ultima`,e=>I(e.potenciales_con_ultima_task_mayor_3_dias),!0],[`seguimiento`,e=>I(e.potenciales_sin_seguimiento_mayor_3_dias),!0]],`No hay potenciales pendientes por delegacion.`)}function F(e,t,n,r){let i=document.getElementById(e);if(i){if(i.innerHTML=``,!t.length){i.innerHTML=`<tr><td colspan="${n.length}">${H(r)}</td></tr>`;return}t.forEach(e=>{let t=n.map(([t,n,r],i)=>{let a=n(e),o=i===0?`strong`:`span`;return`<td${r?` class="num"`:``}><${o}>${H(a)}</${o}></td>`}).join(``);i.insertAdjacentHTML(`beforeend`,`<tr>${t}</tr>`)})}}function I(t){return t==null||t===``?`-`:e.format(Number(t))}function L(e){return e==null||Number.isNaN(Number(e))?`-`:`${Math.round(Number(e)*100)}%`}function R(e){if(e==null||Number.isNaN(Number(e)))return`-`;let t=Number(e)*60;return t<1?`Inmediata`:t<60?`${Math.round(t)} min`:`${Math.round(Number(e))} h`}function z(e,t){return t.is_ratio?L(e):(t.key||``).includes(`tiempo_`)?R(e):I(e)}function B(t,n){if(t==null||Number.isNaN(Number(t)))return`-`;let r=Number(t),i=r>0?`+`:r<0?`-`:``;return n.is_ratio?`${i}${Math.round(r*100)} pp`:(n.key||``).includes(`tiempo_`)?`${i}${R(Math.abs(r))}`:`${i}${e.format(r)}`}function V(e){return e?String(e).slice(0,10):`-`}function H(e){return String(e).replaceAll(`&`,`&amp;`).replaceAll(`<`,`&lt;`).replaceAll(`>`,`&gt;`).replaceAll(`"`,`&quot;`).replaceAll(`'`,`&#039;`)}