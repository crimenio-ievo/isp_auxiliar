/* Motor do diagrama unifilar. Sem dependências externas.
 * state usa acessores para acompanhar a recarga da topologia no mapa.
 * Os callbacks mantêm a apresentação compartilhada no HTML.
 */
window.ISPMapDiagram = function createIspMapDiagram(state, deps){
"use strict";
const {
  FIBER_COLOR_HEX,
  TYPE_LABEL,
  addCable,
  addConnection,
  addSplitter,
  cablesAtNode,
  closeModal,
  escapeHtml,
  fieldsHtml,
  measurementsFor,
  modalEl,
  nodeById,
  openCtxMenu,
  overlay,
  portsOf,
  renderAll,
  splitterOutLabel,
  splitterPortById,
  toast
} = deps;

/* ============================================================
   DIAGRAMA UNIFILAR (nível 2) — EDITOR
   Representa o interior de uma caixa: cabos que entram/saem, fibras
   individuais, fusões, splitters e portas. Admin edita; técnico só consulta.
   ============================================================ */
let diagramNodeId = null;
let dgSelPin = null;      // pino selecionado (origem de uma nova conexão)
let dgSelConn = null;     // conexão selecionada
let dgSelSplitter = null; // splitter selecionado
let dgTraceFiber = null;  // fibra em destaque (tracing)
let isAdmin = true;

/* --- Perdas de referência ---
   Valores típicos de datasheet de fabricante (splitters PLC). São padrões do
   sistema, NÃO vieram da Planilha Fibra. Servem para estimar potência; a medição
   real de campo continua sendo registrada em separado. */
const IL_BALANCEADO = {'1x2':3.6,'1x4':7.2,'1x8':10.4,'1x16':13.7,'1x32':17.1,'1x64':21.5};
const IL_SAIDA_TAP  = {1:21.0,2:18.7,5:13.4,10:10.9,15:9.6,20:8.0,25:6.8,30:5.9,35:5.2,40:4.6,
                       45:4.0,50:3.6,55:3.2,60:2.6,65:2.3,70:2.0,75:1.6,80:1.3,85:1.0,90:0.7,95:0.5,98:0.4,99:0.3};
const PERDA_FUSAO = 0.2;     // dB por fusão (constante de projeto — confirmar em campo)
const PERDA_CONECTOR = 0.35; // dB por par conectorizado (valor de catálogo)
let dgJoinMode = 'fusao';    // como a próxima emenda será feita: fusao | conector
const CATALOGO_SPLITTERS = {
  balanceado:    ['1x2','1x4','1x8','1x16','1x32','1x64'],
  desbalanceado: ['1/99','2/98','5/95','10/90','15/85','20/80','25/75','30/70','35/65','40/60','45/55','50/50']
};
const TIPOS_SPLITTER = CATALOGO_SPLITTERS.balanceado.concat(CATALOGO_SPLITTERS.desbalanceado);

function connectionsAtNode(nid2){ return state.connections.filter(c=>c.nodeId===nid2); }
function refEq(a,b){ return a && b && a.type===b.type && a.id===b.id; }
function pinKey(ref){ return ref ? ref.type+':'+ref.id : null; }

function splitterLoss(splitter, port){
  if(splitter.balanceado) return IL_BALANCEADO[splitter.razao] || 10.4;
  const p = port.percentual;
  if(IL_SAIDA_TAP[p]!==undefined) return IL_SAIDA_TAP[p];
  return 10*Math.log10(100/Math.max(1,p));   // fallback teórico
}

/* --- Potência: propaga do ponto de entrada por fusões e splitters --- */
function computeBoxPowers(node){
  const pow = {};
  const conns = connectionsAtNode(node.id);
  // entrada: fibra do cabo que vem de montante
  const entryCable = state.cables.find(cb=>{
    const l = state.links.find(x=>x.id===cb.linkId);
    return l && l.to===node.id;
  });
  if(!entryCable) return pow;
  const entryFiber = state.fiberStrands.find(f=>f.cableId===entryCable.id && f.status!=='livre');
  if(!entryFiber) return pow;
  const base = (typeof node.powerIn==='number') ? node.powerIn : -18;
  pow['fiber:'+entryFiber.id] = base;

  const queue = [{type:'fiber', id:entryFiber.id}];
  const seen = new Set([pinKey(queue[0])]);
  let guard = 0;
  while(queue.length && guard++ < 500){
    const item = queue.shift();
    const val = pow[pinKey(item)];
    if(val===undefined) continue;
    conns.forEach(cn=>{
      let other=null;
      if(refEq(cn.origem,item)) other=cn.destino;
      else if(refEq(cn.destino,item)) other=cn.origem;
      if(!other) return;
      const k = pinKey(other);
      if(seen.has(k)) return;
      let next = val;
      next = val - perdaDaConexao(cn);   // fusão, conector ou passante (0 dB)
      if(other.type==='splitterPort'){
        const fo = splitterPortById(other.id);
        if(fo && fo.port.papel==='saida') next = val - splitterLoss(fo.splitter, fo.port);
      }
      seen.add(k); pow[k]=next; queue.push(other);
      // atravessa o splitter: entrada alimenta todas as saídas
      if(other.type==='splitterPort'){
        const fo = splitterPortById(other.id);
        if(fo && fo.port.papel==='entrada'){
          fo.splitter.ports.filter(p=>p.papel==='saida').forEach(p=>{
            const kk='splitterPort:'+p.id;
            if(seen.has(kk)) return;
            seen.add(kk);
            pow[kk] = next - splitterLoss(fo.splitter, p);
            queue.push({type:'splitterPort', id:p.id});
          });
        }
      }
    });
  }
  return pow;
}

/* --- Tracing dentro da caixa a partir de um pino --- */
function traceFromPin(nodeIdX, startRef){
  const conns = connectionsAtNode(nodeIdX);
  const pins=new Set([pinKey(startRef)]), used=new Set();
  const queue=[startRef]; let guard=0;
  while(queue.length && guard++<500){
    const item=queue.shift();
    conns.forEach(cn=>{
      if(used.has(cn.id)) return;
      let other=null;
      if(refEq(cn.origem,item)) other=cn.destino;
      else if(refEq(cn.destino,item)) other=cn.origem;
      if(!other) return;
      used.add(cn.id);
      const k=pinKey(other);
      if(!pins.has(k)){ pins.add(k); queue.push(other); }
      if(other.type==='splitterPort'){
        const fo=splitterPortById(other.id);
        if(fo){
          const irmaos = fo.port.papel==='entrada'
            ? fo.splitter.ports.filter(p=>p.papel==='saida')
            : fo.splitter.ports.filter(p=>p.papel==='entrada');
          irmaos.forEach(p=>{
            const kk='splitterPort:'+p.id;
            if(!pins.has(kk)){ pins.add(kk); queue.push({type:'splitterPort',id:p.id}); }
          });
        }
      }
    });
  }
  return {pins, conns:used};
}
// mantém compatibilidade com o tracing por fibra
function traceFromFiber(nodeIdX, startFiberId){
  const r = traceFromPin(nodeIdX, {type:'fiber', id:startFiberId});
  const fibers=new Set(), terminalPorts=new Set(), terminalLinks=new Set();
  r.pins.forEach(k=>{
    const [t,id]=k.split(':');
    if(t==='fiber') fibers.add(id);
    if(t==='port') terminalPorts.add(id);
    if(t==='link') terminalLinks.add(id);
  });
  return {fibers, conns:r.conns, ports:new Set(), terminalLinks, terminalPorts, pins:r.pins};
}

/* ============================================================
   LAYOUT do diagrama — com posições editáveis por caixa
   ============================================================ */
const DG = {W:960, H:560, padX:40, blockW:190, splW:74, pinGap:22};
let dgView = {x:0, y:0, k:1};          // zoom/pan do diagrama
let dgDrag = null;                      // arraste de bloco ou pan

function classifyCables(node){
  const entrada=[], saida=[], local=[];
  cablesAtNode(node.id).forEach(cb=>{
    const l = state.links.find(x=>x.id===cb.linkId);
    if(cb.localAtNode===node.id) local.push(cb);
    else if(l && l.to===node.id) entrada.push(cb);
    else saida.push(cb);
  });
  return {entrada, saida, local};
}

/* posições salvas por caixa: node.dgPos['cable:xx'] = {x,y} */
function dgPosOf(node, key, fallback){
  if(node.dgPos && node.dgPos[key]) return node.dgPos[key];
  return fallback;
}
function dgSetPos(node, key, x, y){
  if(!node.dgPos) node.dgPos = {};
  node.dgPos[key] = {x, y};
}

function buildLayout(node){
  const {entrada, saida, local} = classifyCables(node);
  const spls = state.splitters.filter(s=>s.nodeId===node.id);
  const nodePorts = portsOf(node.id);
  const pins = {}, blocks = [], splBoxes = [];
  const blockH = n => 40 + n*DG.pinGap + 12;

  /* Item A: sem cabo de entrada nem local, o 1º cabo de saída assume o papel
     visual de "lado esquerdo" — evita as duas colunas caindo no mesmo lado e
     o outro ficar vazio. Só o primeiro; o resto de saída segue à direita. */
  let saidaEsquerda = null, saidaRestante = saida;
  if(entrada.length===0 && local.length===0 && saida.length>0){
    saidaEsquerda = saida[0];
    saidaRestante = saida.slice(1);
  }

  /* Item B: o lado de um bloco (e, com ele, o lado dos pinos que ele carrega)
     não é mais fixo pela classificação — é recalculado pela posição atual
     (dgPos, se o bloco foi arrastado). Arrastar um cabo pro lado oposto do
     diagrama já vira a marcação dos pinos automaticamente. */
  const ladoPor = x => x < DG.W/2 ? 'left' : 'right';

  // ---- coluna esquerda: cabos de entrada, locais e (se for o caso) o 1º de saída
  let y = 30;
  entrada.concat(local).concat(saidaEsquerda?[saidaEsquerda]:[]).forEach(cb=>{
    const fibs = state.fiberStrands.filter(f=>f.cableId===cb.id).sort((a,b)=>a.numero-b.numero);
    const h = blockH(fibs.length);
    const key = 'cable:'+cb.id;
    const p = dgPosOf(node, key, {x:DG.padX, y});
    const side = ladoPor(p.x);
    blocks.push({key, cable:cb, fibers:fibs, x:p.x, y:p.y, w:DG.blockW, h, side});
    fibs.forEach((f,i)=> pins['fiber:'+f.id] = {x: side==='left'?p.x+DG.blockW:p.x, y:p.y+40+i*DG.pinGap, side, fiber:f});
    y += h + 26;
  });

  // ---- coluna direita: cabos de saída restantes + bloco de portas
  let y2 = 30;
  const rightX = DG.W - DG.padX - DG.blockW;
  saidaRestante.forEach(cb=>{
    const fibs = state.fiberStrands.filter(f=>f.cableId===cb.id).sort((a,b)=>a.numero-b.numero);
    const h = blockH(fibs.length);
    const key = 'cable:'+cb.id;
    const p = dgPosOf(node, key, {x:rightX, y:y2});
    const side = ladoPor(p.x);
    blocks.push({key, cable:cb, fibers:fibs, x:p.x, y:p.y, w:DG.blockW, h, side});
    fibs.forEach((f,i)=> pins['fiber:'+f.id] = {x: side==='left'?p.x+DG.blockW:p.x, y:p.y+40+i*DG.pinGap, side, fiber:f});
    y2 += h + 26;
  });
  if(nodePorts.length){
    const h = blockH(nodePorts.length);
    const p = dgPosOf(node, 'ports', {x:rightX, y:y2});
    const side = ladoPor(p.x);
    blocks.push({key:'ports', portsBlock:true, ports:nodePorts, x:p.x, y:p.y, w:DG.blockW, h, side});
    nodePorts.forEach((pt,i)=> pins['port:'+pt.id] = {x: side==='left'?p.x+DG.blockW:p.x, y:p.y+40+i*DG.pinGap, side, port:pt});
    y2 += h + 26;
  }

  // ---- centro: splitters (desenhados como triângulo)
  let y3 = 70;
  const midX = (DG.padX+DG.blockW + rightX)/2 - DG.splW/2;
  spls.forEach(s=>{
    const outs = s.ports.filter(p=>p.papel==='saida');
    const h = Math.max(64, outs.length*18+26);
    const key = 'spl:'+s.id;
    const p = dgPosOf(node, key, {x:midX, y:y3});
    splBoxes.push({key, splitter:s, x:p.x, y:p.y, w:DG.splW, h, outs});
    const inP = s.ports.find(q=>q.papel==='entrada');
    pins['splitterPort:'+inP.id] = {x:p.x, y:p.y+h/2, side:'right', splIn:true};
    outs.forEach((q,i)=>{
      const yy = outs.length===1 ? p.y+h/2 : p.y+14+i*((h-28)/(outs.length-1));
      pins['splitterPort:'+q.id] = {x:p.x+DG.splW, y:yy, side:'left', port:q, splitter:s};
    });
    y3 += h + 34;
  });

  return {blocks, splBoxes, pins};
}

function pinColor(key, layout){
  const p = layout.pins[key];
  if(!p) return '#6e7681';
  if(p.fiber) return FIBER_COLOR_HEX[p.fiber.cor] || '#888';
  if(p.port)  return p.port.clientName ? '#3fb950' : '#3a4353';
  return '#4fd1c5';
}
function isPinConnected(key, node){
  return connectionsAtNode(node.id).some(cn=> pinKey(cn.origem)===key || pinKey(cn.destino)===key);
}
/* uma porta só é vendável se houver saída de splitter alimentando ela */
function portaAlimentada(portId, node){
  return connectionsAtNode(node.id).some(cn=>
    cn.tipo==='splitter_out' && cn.destino.type==='port' && cn.destino.id===portId);
}

/* rota ortogonal; devolve os dois trechos separados pelo ponto da emenda,
   para a cor poder mudar exatamente ali (ex.: violeta vira vermelha) */
function routeParts(a,b){
  const outA = a.side==='left' ? 22 : -22;
  const outB = b.side==='right' ? -22 : 22;
  const ax=a.x+outA, bx=b.x+outB;
  const mx = ax + (bx-ax)/2;
  const my = (a.y+b.y)/2;
  return {
    mx, my,
    dA:`M ${a.x} ${a.y} L ${ax} ${a.y} L ${mx} ${a.y} L ${mx} ${my}`,
    dB:`M ${mx} ${my} L ${mx} ${b.y} L ${bx} ${b.y} L ${b.x} ${b.y}`
  };
}

/* ============================================================
   RENDER
   ============================================================ */
function renderDiagram(){
  const node = nodeById(diagramNodeId);
  if(!node) return;
  document.getElementById('diagram-title').textContent = TYPE_LABEL[node.type]+' — '+node.name;
  document.getElementById('dg-role').textContent = isAdmin ? 'Admin' : 'Técnico';
  ['dg-add-cable','dg-add-splitter','dg-joinsel'].forEach(id=>{
    const el=document.getElementById(id); if(el) el.style.display = isAdmin?'':'none';
  });

  const layout = buildLayout(node);
  const powers = computeBoxPowers(node);
  const trace  = dgTraceFiber ? traceFromPin(node.id, dgTraceFiber) : null;
  const conns  = connectionsAtNode(node.id);
  const svgEl  = document.getElementById('diagram-svg');
  svgEl.setAttribute('viewBox', `0 0 ${DG.W} ${DG.H}`);

  const out = [];
  out.push(`<g id="dg-root" transform="translate(${dgView.x},${dgView.y}) scale(${dgView.k})">`);

  // ---- conexões (com marcador de emenda e troca de cor no ponto)
  conns.forEach(cn=>{
    const ka=pinKey(cn.origem), kb=pinKey(cn.destino);
    const a=layout.pins[ka], b=layout.pins[kb];
    if(!a||!b) return;
    const rp = routeParts(a,b);
    const colorA = pinColor(ka,layout), colorB = pinColor(kb,layout);
    let cls='dconn';
    if(dgSelConn===cn.id) cls+=' sel';
    else if(trace) cls += trace.conns.has(cn.id) ? ' hl' : ' dim';
    out.push(`<path class="${cls}" data-conn="${cn.id}" d="${rp.dA}" stroke="${colorA}"/>`);
    out.push(`<path class="${cls}" data-conn="${cn.id}" d="${rp.dB}" stroke="${colorB}"/>`);

    const em = cn.emenda;
    if(em==='fusao'){
      out.push(`<g class="emenda" data-conn="${cn.id}">
        <rect x="${rp.mx-6}" y="${rp.my-5}" width="12" height="10" rx="2" fill="#0d1117" stroke="#c7d1dc" stroke-width="1.3"/>
        <text class="emenda-lbl" x="${rp.mx+11}" y="${rp.my+3}">fusão</text></g>`);
    } else if(em==='conector'){
      out.push(`<g class="emenda" data-conn="${cn.id}">
        <path d="M ${rp.mx-8} ${rp.my-6} L ${rp.mx-2.5} ${rp.my-6} L ${rp.mx-2.5} ${rp.my+6} L ${rp.mx-8} ${rp.my+6}" fill="none" stroke="#e8c874" stroke-width="1.7"/>
        <path d="M ${rp.mx+8} ${rp.my-6} L ${rp.mx+2.5} ${rp.my-6} L ${rp.mx+2.5} ${rp.my+6} L ${rp.mx+8} ${rp.my+6}" fill="none" stroke="#e8c874" stroke-width="1.7"/>
        <text class="emenda-lbl conn" x="${rp.mx+13}" y="${rp.my+3}">conector</text></g>`);
    } else if(cn.tipo==='passante'){
      out.push(`<text class="emenda-lbl pass" x="${rp.mx+6}" y="${rp.my-4}">passante</text>`);
    }
  });

  // ---- blocos
  layout.blocks.forEach(bl=>{
    const drag = isAdmin ? `data-drag="${bl.key}"` : '';
    out.push(`<g class="dblock" data-block="${bl.key}" ${drag}>`);
    out.push(`<rect class="cbox" x="${bl.x}" y="${bl.y}" width="${bl.w}" height="${bl.h}" rx="6"/>`);
    if(bl.portsBlock){
      const alim   = bl.ports.filter(p=>portaAlimentada(p.id,node));
      const ocup   = bl.ports.filter(p=>p.clientName);
      const orfas  = ocup.filter(p=>!portaAlimentada(p.id,node));   // cliente SEM alimentação
      const livres = alim.filter(p=>!p.clientName).length;          // alimentada E sem cliente
      const semAlim = bl.ports.length - alim.length - orfas.length; // vaga, sem alimentação
      out.push(`<text class="cbox-title" x="${bl.x+12}" y="${bl.y+20}">PORTAS</text>`);
      out.push(`<text class="cbox-sub" x="${bl.x+12}" y="${bl.y+33}">${ocup.length} em uso · ${livres} livres · ${semAlim} sem alimentação</text>`);
      if(orfas.length) out.push(`<text class="cbox-sub" x="${bl.x+12}" y="${bl.y+45}" fill="#f85149">${orfas.length} cliente(s) em porta sem alimentação</text>`);
    } else {
      const cb=bl.cable;
      const livres=bl.fibers.filter(f=>f.status==='livre').length;
      const extra = cb.semRota ? ' · SEM ROTA' : (cb.km!==undefined ? ' · '+cb.km.toFixed(2)+' km' : '');
      out.push(`<text class="cbox-title" x="${bl.x+12}" y="${bl.y+20}">${escapeHtml(cb.nome)}</text>`);
      out.push(`<text class="cbox-sub" x="${bl.x+12}" y="${bl.y+33}" ${cb.semRota?'fill="#d29922"':''}>${cb.tipo} · ${cb.codigo} · ${livres} livres${extra}</text>`);
    }
    out.push(`</g>`);

    // pinos
    if(bl.portsBlock){
      bl.ports.forEach((p,i)=>{
        const key='port:'+p.id, pin=layout.pins[key];
        const occ=!!p.clientName, alim=portaAlimentada(p.id,node);
        const dim = trace && !trace.pins.has(key);
        const fill = occ ? '#3fb950' : (alim ? '#161b23' : '#11151c');
        const stroke = occ ? '#3fb950' : (alim ? '#4fd1c5' : '#3a4353');
        out.push(`<g class="fpin${dgSelPin===key?' sel':''}" data-pin="${key}" opacity="${dim?0.25:1}">
          <circle class="fpin-ring" cx="${pin.x}" cy="${pin.y}" r="7" fill="${fill}" stroke="${stroke}" ${alim?'':'stroke-dasharray="2,2"'}/>
          <text class="fpin-num" x="${pin.x}" y="${pin.y+3}" text-anchor="middle" fill="${occ?'#04211f':'#7c8798'}">${i+1}</text>
          <title>${escapeHtml(p.label)} — ${occ?escapeHtml(p.clientName):(alim?'livre (alimentada)':'sem alimentação')}</title></g>`);
        const txt = occ ? p.clientName : (alim ? 'livre' : 'sem alimentação');
        out.push(`<text class="fpin-label${alim?'':' nofeed'}" x="${pin.x+14}" y="${pin.y+3}">${escapeHtml(txt)}</text>`);
      });
    } else {
      bl.fibers.forEach(f=>{
        const key='fiber:'+f.id, pin=layout.pins[key];
        const conn=isPinConnected(key,node);
        const px = powers['fiber:'+f.id];
        const temSinal = px!==undefined;
        const hex=FIBER_COLOR_HEX[f.cor]||'#888';
        const dark=['Branco','Amarelo','Aqua','Rosa','Cinza'].includes(f.cor);
        const dim = trace && !trace.pins.has(key);
        const extra = (conn && !temSinal)
          ? `<circle cx="${pin.x}" cy="${pin.y}" r="11.5" fill="none" stroke="#6e7681" stroke-width="1.2" stroke-dasharray="2,2"/>` : '';
        out.push(`<g class="fpin${dgSelPin===key?' sel':''}" data-pin="${key}" opacity="${dim?0.25:1}">
          ${extra}
          <circle class="fpin-ring" cx="${pin.x}" cy="${pin.y}" r="8" fill="${conn?hex:'#161b23'}" fill-opacity="${conn&&!temSinal?0.45:1}" stroke="${hex}" ${conn?'':'stroke-dasharray="2.5,2"'}/>
          <text class="fpin-num" x="${pin.x}" y="${pin.y+3}" text-anchor="middle" fill="${conn&&temSinal?(dark?'#0b0e13':'#fff'):hex}">${f.numero}</text>
          <title>F${f.numero} ${f.cor} — ${f.status}${conn?(temSinal?' · com sinal ('+px.toFixed(1)+' dBm)':' · emendada, SEM sinal'):''}</title></g>`);
        const lx = bl.side==='left' ? pin.x-14 : pin.x+14;
        const anc = bl.side==='left' ? 'end' : 'start';
        if(temSinal) out.push(`<text class="pwr${px<-27?' bad':''}" x="${lx}" y="${pin.y+3}" text-anchor="${anc}">${px.toFixed(1)}</text>`);
        else if(conn) out.push(`<text class="pwr nosig" x="${lx}" y="${pin.y+3}" text-anchor="${anc}">sem sinal</text>`);
      });
    }
  });

  // ---- splitters em forma de triângulo (símbolo clássico)
  layout.splBoxes.forEach(sb=>{
    const s=sb.splitter, x=sb.x, y=sb.y, w=sb.w, h=sb.h;
    const desbal = !s.balanceado;
    const cor = desbal ? '#d29922' : '#4fd1c5';
    const drag = isAdmin ? `data-drag="${sb.key}"` : '';
    out.push(`<g class="spl${dgSelSplitter===s.id?' sel':''}" data-spl="${s.id}" ${drag}>
      <polygon class="spl-body" points="${x},${y+h/2} ${x+w},${y} ${x+w},${y+h}" fill="#1b2430" stroke="${cor}" stroke-width="${dgSelSplitter===s.id?3:1.7}"/>
      <text class="spl-label" x="${x+w*0.62}" y="${y+h/2+3}" text-anchor="middle" fill="${cor}">${s.razao}</text>
      <text class="spl-kind" x="${x+w/2}" y="${y-6}" text-anchor="middle" fill="${cor}">${desbal?'DESBALANCEADO':'balanceado'}</text>
    </g>`);
    s.ports.forEach(p=>{
      const key='splitterPort:'+p.id, pin=layout.pins[key];
      if(!pin) return;
      const dim = trace && !trace.pins.has(key);
      out.push(`<g class="fpin${dgSelPin===key?' sel':''}" data-pin="${key}" opacity="${dim?0.25:1}">
        <circle class="fpin-ring" cx="${pin.x}" cy="${pin.y}" r="5" fill="#0d1117" stroke="${cor}"/></g>`);
      if(p.papel==='saida'){
        out.push(`<text class="fpin-label" x="${pin.x+9}" y="${pin.y-5}">${splitterOutLabel(p,s)}</text>`);
        const px=powers[key];
        if(px!==undefined) out.push(`<text class="pwr${px<-27?' bad':''}" x="${pin.x+9}" y="${pin.y+8}">${px.toFixed(1)}</text>`);
      }
    });
  });

  out.push('</g>');
  svgEl.innerHTML = out.join('');
  renderDiagramSidePanels(node, layout, powers, trace);
  updateDiagramHint();
}

function updateDiagramHint(){
  const el = document.getElementById('diagram-hint');
  if(!isAdmin){
    el.className='diagram-hint show locked';
    el.innerHTML='Perfil <b>Técnico</b>: consulta e medições. A edição da caixa é restrita a administradores.';
    return;
  }
  el.className='diagram-hint show';
  if(dgSelPin) el.innerHTML='Pino selecionado — clique em <b>outro pino</b> para emendar, ou no mesmo para cancelar.';
  else if(dgSelConn) el.innerHTML='Emenda selecionada — troque entre <b>fusão e conector</b> no painel à direita.';
  else el.innerHTML='Clique numa <b>fibra</b> para ver o caminho · arraste <b>blocos e splitters</b> para organizar · roda do mouse dá <b>zoom</b>.';
}

/* ---- interação: clique, arraste de blocos, pan e zoom ---- */
function dgPointFromEvent(e){
  const svgEl=document.getElementById('diagram-svg');
  const r=svgEl.getBoundingClientRect();
  const sx=(e.clientX-r.left)*(DG.W/r.width);
  const sy=(e.clientY-r.top)*(DG.H/r.height);
  return {x:(sx-dgView.x)/dgView.k, y:(sy-dgView.y)/dgView.k, sx, sy};
}

/* A seleção no diagrama é resolvida no pointerdown/pointerup, e NÃO por eventos
   'click'. Motivo: o pointerdown chama setPointerCapture no <svg>; com a captura
   ativa o navegador reencaminha os eventos de mouse de compatibilidade (o click
   inclusive) para o elemento capturador, então um listener no pino/conexão/splitter
   nunca era disparado. É o mesmo desenho que o mapa já usa: decide o alvo na
   descida e age na subida. */
function alvoDiagrama(t){
  if(!t || !t.closest) return null;
  const pin = t.closest('[data-pin]');   if(pin) return {tipo:'pin',  id:pin.getAttribute('data-pin')};
  const cn  = t.closest('[data-conn]');  if(cn)  return {tipo:'conn', id:cn.getAttribute('data-conn')};
  const sp  = t.closest('[data-spl]');   if(sp)  return {tipo:'spl',  id:sp.getAttribute('data-spl')};
  return null;
}

function initDiagramCanvas(){
  const svgEl=document.getElementById('diagram-svg');

  svgEl.addEventListener('pointerdown',(e)=>{
    if(e.button===2 || e.button===1) return;  // direito/meio: reservado ao menu de contexto
    const node = nodeById(diagramNodeId); if(!node) return;
    const p = dgPointFromEvent(e);
    const alvo = alvoDiagrama(e.target);                    // o que foi clicado, decidido aqui
    const dragEl = e.target.closest ? e.target.closest('[data-drag]') : null;
    if(dragEl && isAdmin && (!alvo || alvo.tipo==='spl')){  // pino e conexão não arrastam
      const key = dragEl.getAttribute('data-drag');
      const layout = buildLayout(node);
      const bl = layout.blocks.find(b=>b.key===key) || layout.splBoxes.find(b=>b.key===key);
      if(bl) dgDrag = {kind:'block', key, alvo, offX:p.x-bl.x, offY:p.y-bl.y, cx0:e.clientX, cy0:e.clientY, moved:false};
    }
    if(!dgDrag) dgDrag = {kind:'pan', alvo, x0:p.sx, y0:p.sy, vx:dgView.x, vy:dgView.y, moved:false};
    try{ svgEl.setPointerCapture(e.pointerId); }catch(err){}
  });

  svgEl.addEventListener('pointermove',(e)=>{
    if(!dgDrag) return;
    const node = nodeById(diagramNodeId); if(!node) return;
    const p = dgPointFromEvent(e);
    if(dgDrag.kind==='block'){
      // sem limiar, um pointermove de 0px já redesenhava o SVG e engolia o clique
      if(!dgDrag.moved && Math.hypot(e.clientX-dgDrag.cx0, e.clientY-dgDrag.cy0) <= 4) return;
      dgDrag.moved = true;
      dgSetPos(node, dgDrag.key, Math.round(p.x-dgDrag.offX), Math.round(p.y-dgDrag.offY));
      renderDiagram();
    } else {
      if(Math.hypot(p.sx-dgDrag.x0, p.sy-dgDrag.y0) > 4){
        dgDrag.moved = true;
        dgView.x = dgDrag.vx + (p.sx-dgDrag.x0);
        dgView.y = dgDrag.vy + (p.sy-dgDrag.y0);
        renderDiagram();
      }
    }
  });

  svgEl.addEventListener('pointerup',(e)=>{
    const st = dgDrag; dgDrag = null;
    try{ svgEl.releasePointerCapture(e.pointerId); }catch(err){}
    if(!st || st.moved) return;                    // foi arraste ou pan: não seleciona
    const node = nodeById(diagramNodeId); if(!node) return;
    const alvo = st.alvo;
    if(!alvo){                                     // área vazia: limpa a seleção
      dgSelPin=null; dgSelConn=null; dgSelSplitter=null; dgTraceFiber=null; renderDiagram(); return;
    }
    if(alvo.tipo==='pin')  { onPinClick(alvo.id, node); return; }
    if(alvo.tipo==='conn') { dgSelConn=alvo.id; dgSelPin=null; dgSelSplitter=null; renderDiagram(); return; }
    if(alvo.tipo==='spl')  { dgSelSplitter=alvo.id; dgSelConn=null; dgSelPin=null; renderDiagram(); return; }
  });

  svgEl.addEventListener('pointercancel',()=>{ dgDrag=null; });

  svgEl.addEventListener('wheel',(e)=>{
    e.preventDefault();
    const p = dgPointFromEvent(e);
    const f = e.deltaY<0 ? 1.15 : 1/1.15;
    const k2 = Math.max(0.3, Math.min(3.5, dgView.k*f));
    dgView.x = p.sx - (p.sx - dgView.x)*(k2/dgView.k);
    dgView.y = p.sy - (p.sy - dgView.y)*(k2/dgView.k);
    dgView.k = k2;
    renderDiagram();
  },{passive:false});

  document.getElementById('dg-zoom-in').addEventListener('click',()=>{ dgView.k=Math.min(3.5,dgView.k*1.25); renderDiagram(); });
  document.getElementById('dg-zoom-out').addEventListener('click',()=>{ dgView.k=Math.max(0.3,dgView.k/1.25); renderDiagram(); });
  document.getElementById('dg-fit').addEventListener('click',()=>{ dgView={x:0,y:0,k:1}; renderDiagram(); });
  document.getElementById('dg-reset-pos').addEventListener('click',()=>{
    const node=nodeById(diagramNodeId); if(!node) return;
    node.dgPos={}; dgView={x:0,y:0,k:1};
    toast('Posições do diagrama restauradas.');
    renderDiagram();
  });
}

function rotuloTipoConexao(t){
  return {fusao:'fusão', conector:'conectorizado', drop:'drop', splitter_in:'entrada do splitter',
          splitter_out:'saída do splitter', passante:'passante'}[t] || t;
}
function perdaDaConexao(cn){
  const em = (typeof cn==='string') ? cn : (cn && cn.emenda);
  if(em==='fusao') return PERDA_FUSAO;
  if(em==='conector') return PERDA_CONECTOR;
  return 0;
}
function refFromKey(k){ const i=k.indexOf(':'); return {type:k.slice(0,i), id:k.slice(i+1)}; }

function onPinClick(key, node){
  const ref = refFromKey(key);
  // técnico: clique só destaca o caminho
  if(!isAdmin){
    dgTraceFiber = ref; dgSelPin=null; renderDiagram(); return;
  }
  if(!dgSelPin){
    dgSelPin = key;
    dgTraceFiber = ref;
    dgSelConn=null; dgSelSplitter=null;
    renderDiagram();
    return;
  }
  if(dgSelPin===key){ dgSelPin=null; renderDiagram(); return; }
  const a = refFromKey(dgSelPin), b = ref;
  const erro = validarConexao(a,b,node);
  if(erro){ toast(erro); return; }
  criarConexao(a,b,node);
}

function validarConexao(a,b,node){
  const existe = connectionsAtNode(node.id).some(cn=>
    (refEq(cn.origem,a)&&refEq(cn.destino,b)) || (refEq(cn.origem,b)&&refEq(cn.destino,a)));
  if(existe) return 'Esses dois pontos já estão conectados.';
  if(a.type==='port' && b.type==='port') return 'Duas portas de cliente não se conectam entre si.';
  const ocupado = (r)=> connectionsAtNode(node.id).filter(cn=> pinKey(cn.origem)===pinKey(r)||pinKey(cn.destino)===pinKey(r)).length;
  if(a.type==='splitterPort' && ocupado(a)>=1) return 'Essa via do splitter já está ocupada.';
  if(b.type==='splitterPort' && ocupado(b)>=1) return 'Essa via do splitter já está ocupada.';
  if(a.type==='fiber' && ocupado(a)>=2) return 'Essa fibra já tem conexões nas duas pontas.';
  if(b.type==='fiber' && ocupado(b)>=2) return 'Essa fibra já tem conexões nas duas pontas.';
  return null;
}

function criarConexao(a,b,node,emendaForcada){
  // "papel" = função topológica; "emenda" = como foi feito fisicamente. São independentes.
  let papel='fusao';
  if(a.type==='splitterPort'||b.type==='splitterPort'){
    const sp = a.type==='splitterPort'?a:b;
    const info = splitterPortById(sp.id);
    papel = (info && info.port.papel==='entrada') ? 'splitter_in' : 'splitter_out';
  } else if(a.type==='port'||b.type==='port'){
    papel='drop';
  }
  let origem=a, destino=b;
  if(papel==='splitter_out' && a.type!=='splitterPort'){ origem=b; destino=a; }
  if(papel==='splitter_in'  && a.type==='splitterPort'){ origem=b; destino=a; }
  const cn = addConnection(node.id, papel, origem, destino);
  // saída de splitter pro cliente: por padrão é conectorizado (é raríssimo fusionar
  // direto no splitter em campo). O toggle Fusão/Conector da barra continua valendo
  // pros outros papéis (passante, splitter_in); pra forçar fusão numa saída específica,
  // use "Emendar por fusão" no menu de botão direito (emendaForcada).
  cn.emenda = emendaForcada || (papel==='splitter_out' ? 'conector' : dgJoinMode);
  [a,b].forEach(r=>{ if(r.type==='fiber'){ const f=state.fiberStrands.find(x=>x.id===r.id); if(f&&f.status==='livre') f.status='ocupada'; }});
  dgSelPin=null; dgTraceFiber=null;
  toast('Emenda criada: '+(cn.emenda==='fusao'?'fusão':'conectorizada')+'.');
  renderDiagram();
}

/* ---- painéis laterais do diagrama ---- */
function renderDiagramSidePanels(node, layout, powers, trace){
  // esquerda: cabos da caixa
  const {entrada, saida, local} = classifyCables(node);
  const colL = document.getElementById('diagram-cables');
  function cableRow(cb, papel){
    const fibs=state.fiberStrands.filter(f=>f.cableId===cb.id);
    const livres=fibs.filter(f=>f.status==='livre').length;
    const tubos=[...new Set(fibs.map(f=>f.tubo))];
    return `<div class="cable-block">
      <h5>${escapeHtml(cb.nome)}</h5>
      <div class="cable-meta">${papel} · ${cb.tipo} · ${cb.codigo}</div>
      <div class="cable-meta">${livres}/${fibs.length} livres · ${tubos.length} tubo(s)${cb.construtivo?' · '+cb.construtivo:''}</div>
      <div class="cable-meta" ${cb.semRota?'style="color:var(--yellow)"':''}>${cb.semRota?'sem rota no mapa':(cb.km!==undefined?cb.km.toFixed(2)+' km':'rota no mapa')}</div>
      ${isAdmin?`<button class="btn btn-sm" data-delcable="${cb.id}" style="margin-top:5px;width:100%;justify-content:center;">Remover cabo</button>`:''}
    </div>`;
  }
  colL.innerHTML =
    (entrada.map(cb=>cableRow(cb,'entrada')).join('')) +
    (local.map(cb=>cableRow(cb,'local')).join('')) +
    (saida.map(cb=>cableRow(cb,'saída')).join('')) +
    `<div class="sb-section"><h4>Legenda</h4>
      <div class="legend-chip"><i style="background:#3fb950"></i>fibra com sinal</div>
      <div class="legend-chip"><i style="background:#3fb950;opacity:.45;box-shadow:0 0 0 2px #2a3140"></i>emendada, sem sinal</div>
      <div class="legend-chip"><i style="border:1.5px dashed #7c8798"></i>livre</div>
      <div class="cable-meta" style="margin-top:8px;">Marcador <b>▭</b> = fusão · <b>] [</b> = conectorizado.</div>
      <div class="cable-meta" style="margin-top:4px;">dBm é <b>estimado</b> por perdas de catálogo (fusão ${PERDA_FUSAO} dB · conector ${PERDA_CONECTOR} dB).</div>
    </div>`;
  colL.querySelectorAll('[data-delcable]').forEach(b=>{
    b.addEventListener('click',()=> removerCabo(b.getAttribute('data-delcable'), node));
  });

  // direita: propriedades da seleção
  const colR = document.getElementById('diagram-info');
  if(dgSelConn){
    const cn = state.connections.find(x=>x.id===dgSelConn);
    const dA = describeRef(cn.origem), dB = describeRef(cn.destino);
    colR.innerHTML = `
      ${fieldsHtml([
        {label:'Função', value: rotuloTipoConexao(cn.tipo)},
        {label:'Perda aplicada', value: perdaDaConexao(cn) ? perdaDaConexao(cn)+' dB' : '—'},
        {label:'Ponto A', value: dA, full:true},
        {label:'Ponto B', value: dB, full:true}
      ])}
      ${isAdmin?`
      <div class="form-field"><label>Como está emendado</label>
        <select id="dg-emenda">
          <option value="fusao" ${cn.emenda==='fusao'?'selected':''}>Fusão (${PERDA_FUSAO} dB)</option>
          <option value="conector" ${cn.emenda==='conector'?'selected':''}>Conectorizado (${PERDA_CONECTOR} dB)</option>
          <option value="passante" ${(!cn.emenda||cn.emenda==='passante')?'selected':''}>Passante / sem emenda (0 dB)</option>
        </select>
      </div>
      <div class="action-row"><button class="btn btn-danger" id="dg-del-conn" style="flex:1 1 100%;">Excluir conexão</button></div>`:''}`;
    const selEm=document.getElementById('dg-emenda');
    if(selEm) selEm.addEventListener('change',()=>{
      cn.emenda = selEm.value==='passante' ? null : selEm.value;
      toast('Emenda alterada para '+(selEm.value==='passante'?'passante':selEm.value==='fusao'?'fusão':'conectorizada')+'. Potências recalculadas.');
      renderDiagram();
    });
    const b=document.getElementById('dg-del-conn');
    if(b) b.addEventListener('click',()=>{
      state.connections = state.connections.filter(x=>x.id!==dgSelConn);
      dgSelConn=null; toast('Conexão removida.'); renderDiagram();
    });
    return;
  }
  if(dgSelSplitter){
    const s = state.splitters.find(x=>x.id===dgSelSplitter);
    const outs = s.ports.filter(p=>p.papel==='saida');
    const outsLivres = outs.filter(p=>!isPinConnected('splitterPort:'+p.id, node));
    const portasLivres = portsOf(node.id).filter(p=>p.status==='livre' && !portaAlimentada(p.id,node));
    const podeLigar = isAdmin && outsLivres.length && portasLivres.length;
    colR.innerHTML = `
      ${fieldsHtml([
        {label:'Splitter', value:s.razao},
        {label:'Tipo', value:s.balanceado?'Balanceado':'Desbalanceado (tap)'},
        {label:'Vias de saída', value:outs.length}
      ])}
      <div class="section-title">Perda por via (catálogo)</div>
      <div class="port-list">
        ${outs.map(p=>`<div class="port-row"><span class="pl">${splitterOutLabel(p,s)}</span><span class="pf">-${splitterLoss(s,p).toFixed(1)} dB</span></div>`).join('')}
      </div>
      ${podeLigar ? `
      <div class="action-row" style="margin-top:12px;">
        <button class="btn btn-accent" id="dg-spl-autowire" style="flex:1 1 100%;">Ligar saídas às portas livres (${Math.min(outsLivres.length,portasLivres.length)})</button>
      </div>` : ''}
      ${isAdmin?`
      <div class="form-field" style="margin-top:12px;"><label>Simular troca — categoria</label>
        <select id="dg-spl-cat">
          <option value="balanceado"${s.balanceado?' selected':''}>Balanceado (1xN)</option>
          <option value="desbalanceado"${!s.balanceado?' selected':''}>Desbalanceado (tap)</option>
        </select>
      </div>
      <div class="form-field"><label>Modelo</label><select id="dg-spl-tipo"></select></div>
      <div id="dg-spl-preview" class="spl-preview"></div>
      <div class="action-row">
        <button class="btn btn-accent" id="dg-spl-apply">Aplicar troca</button>
        <button class="btn btn-danger" id="dg-spl-del">Remover</button>
      </div>`:''}`;
    const selT=document.getElementById('dg-spl-tipo');
    const selC=document.getElementById('dg-spl-cat');
    const prev=document.getElementById('dg-spl-preview');
    function preencherModelos(){
      if(!selC||!selT) return;
      selT.innerHTML = CATALOGO_SPLITTERS[selC.value].map(t=>`<option value="${t}"${t===s.razao?' selected':''}>${t}</option>`).join('');
    }
    preencherModelos();
    if(selC) selC.addEventListener('change', ()=>{ preencherModelos(); desenharPrevia(); });
    const entradaP = powers['splitterPort:'+s.ports.find(p=>p.papel==='entrada').id];
    function desenharPrevia(){
      if(!selT||!prev) return;
      const t=selT.value;
      const nv = /^1x/.test(t) ? vias(t) : 2;
      const ocupadas = s.ports.filter(p=>p.papel==='saida').filter(p=>
        state.connections.some(cn=>cn.tipo==='splitter_out'&&cn.origem.type==='splitterPort'&&cn.origem.id===p.id
          && cn.destino.type==='port')).length;
      if(t===s.razao){ prev.innerHTML='<span class="muted">Tipo atual — nada muda.</span>'; return; }
      if(entradaP===undefined){ prev.innerHTML='<span class="muted">Sem sinal na entrada: não dá para prever a potência.</span>'; return; }
      const linhas = Array.from({length:nv},(_,i)=>{
        const p = entradaP - perdaPrevista(t,i);
        const rot = /^1x/.test(t) ? ('S'+(i+1)) : (t.split('/')[i]+'%');
        return `<div class="prev-row"><span>${rot}</span><b class="${p<-27?'bad':''}">${p.toFixed(1)} dBm</b></div>`;
      }).join('');
      const perde = ocupadas>nv ? `<div class="prev-warn">${ocupadas-nv} cliente(s) ficariam sem via.</div>` : '';
      prev.innerHTML = `<div class="prev-title">Se aplicar ${t}:</div>${linhas}${perde}`;
    }
    if(selT){ selT.addEventListener('change', desenharPrevia); desenharPrevia(); }
    const ap=document.getElementById('dg-spl-apply');
    if(ap) ap.addEventListener('click',()=> trocarSplitter(s, selT.value, node));
    const dl=document.getElementById('dg-spl-del');
    if(dl) dl.addEventListener('click',()=> removerSplitter(s, node));
    const aw=document.getElementById('dg-spl-autowire');
    if(aw) aw.addEventListener('click',()=> ligarSaidasAsPortasLivres(s, node));
    return;
  }
  if(dgTraceFiber && dgTraceFiber.type==='fiber'){
    const f = state.fiberStrands.find(x=>x.id===dgTraceFiber.id);
    const cb = state.cables.find(x=>x.id===f.cableId);
    const px = powers['fiber:'+f.id];
    const alcancados = trace ? [...trace.pins].filter(k=>k.startsWith('port:')) : [];
    colR.innerHTML = `
      ${fieldsHtml([
        {label:'Cabo', value:escapeHtml(cb.nome)+' · '+cb.tipo},
        {label:'Código', value:cb.codigo},
        {label:'Fibra', value:'nº '+f.numero},
        {label:'Cor', value:f.cor},
        {label:'Tubo', value:f.tubo+' · '+f.tuboCor},
        {label:'Status', value:f.status},
        {label:'Origem do cabo', value: origemDoCabo(cb, node), full:true},
        {label:'Sinal nesta caixa', value: px!==undefined ? 'sim' : 'sem sinal de origem'},
        px!==undefined?{label:'Potência estimada', value:px.toFixed(1)+' dBm'}:null
      ])}
      ${px===undefined && f.status!=='livre' ? '<div class="alert-box has">Esta fibra está emendada, mas não há caminho com sinal até ela dentro desta caixa. Verifique a fusão a montante.</div>' : ''}
      ${alcancados.length?`<div class="section-title">Portas alcançadas</div><div class="port-list">${
        alcancados.map(k=>{const p=state.ports.find(x=>x.id===k.split(':')[1]);
          return `<div class="port-row ${p.clientName?'occ':'free'}"><span class="pn">${escapeHtml(p.label.replace('Porta ',''))}</span><span class="pd"></span><span class="pl">${escapeHtml(p.clientName||'livre')}</span></div>`;}).join('')
      }</div>`:''}`;
    return;
  }
  const mCalc = measurementsFor(node.id).find(m=>m.kind==='calculado');
  const mMed  = measurementsFor(node.id).filter(m=>m.kind==='medido').pop();
  colR.innerHTML = `
    ${fieldsHtml([
      {label:'Caixa', value:escapeHtml(node.name)},
      {label:'Tipo', value:TYPE_LABEL[node.type]},
      {label:'Potência de entrada', value:(typeof node.powerIn==='number'?node.powerIn.toFixed(1):'—')+' dBm'},
      mCalc?{label:'Esperada (cálculo)', value:mCalc.valorDbm+' dBm'}:null,
      mMed?{label:'Última medição', value:mMed.valorDbm+' dBm'}:null
    ])}
    <div class="diagram-empty">Clique em uma fibra, conexão ou splitter para ver os detalhes.</div>`;
}

function origemDoCabo(cb, node){
  if(cb.semRota || !cb.linkId) return 'cabo local, sem rota definida';
  const l = state.links.find(x=>x.id===cb.linkId);
  if(!l) return '—';
  const outro = nodeById(l.from===node.id ? l.to : l.from);
  const sentido = l.to===node.id ? 'vem de ' : 'vai para ';
  return outro ? (sentido+outro.name+(cb.km!==undefined?' · '+cb.km.toFixed(2)+' km':'')) : '—';
}
function describeRef(r){
  if(r.type==='fiber'){
    const f=state.fiberStrands.find(x=>x.id===r.id); const cb=state.cables.find(x=>x.id===f.cableId);
    return escapeHtml(cb.nome)+' · F'+f.numero+' '+f.cor;
  }
  if(r.type==='port'){ const p=state.ports.find(x=>x.id===r.id); return p?(p.label+(p.clientName?' — '+escapeHtml(p.clientName):' (livre)')):'?'; }
  if(r.type==='splitterPort'){ const i=splitterPortById(r.id);
    return i ? ('Splitter '+i.splitter.razao+' · '+(i.port.papel==='entrada'?'entrada':splitterOutLabel(i.port,i.splitter))) : '?'; }
  if(r.type==='link'){ const l=state.links.find(x=>x.id===r.id); return l?('Trecho '+l.id):'?'; }
  return '?';
}

/* ---- ações de edição ---- */
function vias(tipo){
  return /^1x/.test(tipo) ? parseInt(tipo.split('x')[1],10) : 2;
}
function perdaPrevista(tipo, viaIdx){
  if(/^1x/.test(tipo)) return IL_BALANCEADO[tipo] || 10.4;
  const pcts = tipo.split('/').map(v=>parseInt(v,10));
  const p = pcts[viaIdx] !== undefined ? pcts[viaIdx] : pcts[0];
  return IL_SAIDA_TAP[p]!==undefined ? IL_SAIDA_TAP[p] : 10*Math.log10(100/Math.max(1,p));
}

/* Troca o splitter PRESERVANDO os destinos já ligados (clientes continuam
   associados). Se o novo tipo tiver menos vias, o que sobra é informado. */
function trocarSplitter(s, novoTipo, node){
  if(novoTipo===s.razao) return;
  const balanceado = /^1x/.test(novoTipo);
  const saidas = balanceado ? parseInt(novoTipo.split('x')[1],10)
                            : novoTipo.split('/').map(v=>parseInt(v,10));
  const nNovas = balanceado ? saidas : saidas.length;

  // guarda os destinos atuais, na ordem das vias
  const antigas = s.ports.filter(p=>p.papel==='saida');
  const destinos = antigas.map(p=>{
    const cn = state.connections.find(x=>x.tipo==='splitter_out' &&
      x.origem.type==='splitterPort' && x.origem.id===p.id);
    return cn ? cn.destino : null;
  });

  // remove as conexões de saída antigas
  const idsAntigos = antigas.map(p=>p.id);
  state.connections = state.connections.filter(cn=>
    !(cn.tipo==='splitter_out' && cn.origem.type==='splitterPort' && idsAntigos.includes(cn.origem.id)));

  s.razao = novoTipo; s.balanceado = balanceado;
  const inPort = s.ports.find(p=>p.papel==='entrada');
  s.ports = [inPort];
  const lista = balanceado ? Array.from({length:saidas},(_,i)=>i+1) : saidas;
  lista.forEach((v,idx)=> s.ports.push({id:s.id+'-out'+v+'_'+idx, splitterId:s.id, papel:'saida',
    percentual: balanceado?null:v, indice: idx+1}));

  // religa os destinos preservados, via a via
  const novas = s.ports.filter(p=>p.papel==='saida');
  let religados=0; const orfaos=[];
  destinos.forEach((d,i)=>{
    if(!d) return;
    if(i < novas.length){
      const cn = addConnection(node.id,'splitter_out',{type:'splitterPort',id:novas[i].id}, d);
      cn.emenda = 'conector';   // mesma regra do resto do sistema: saída de splitter é conectorizada por padrão
      religados++;
    }
    else orfaos.push(describeRef(d));
  });

  dgSelSplitter=s.id;
  let msg = 'Splitter trocado para '+novoTipo+'. '+religados+' saída(s) religada(s) automaticamente.';
  if(orfaos.length) msg += ' Sem via para: '+orfaos.join(', ')+'.';
  toast(msg);
  renderDiagram();
}
function removerSplitter(s, node){
  if(!confirm('Remover o splitter '+s.razao+' e suas conexões?')) return;
  const ids = s.ports.map(p=>p.id);
  state.connections = state.connections.filter(cn=>
    !(cn.origem.type==='splitterPort'&&ids.includes(cn.origem.id)) &&
    !(cn.destino.type==='splitterPort'&&ids.includes(cn.destino.id)));
  state.splitters = state.splitters.filter(x=>x.id!==s.id);
  dgSelSplitter=null; toast('Splitter removido.'); renderDiagram();
}
function removerCabo(cableId, node){
  const cb = state.cables.find(x=>x.id===cableId);
  if(!cb) return;
  if(!confirm('Remover '+cb.nome+' desta caixa e suas conexões?')) return;
  const fids = state.fiberStrands.filter(f=>f.cableId===cableId).map(f=>f.id);
  state.connections = state.connections.filter(cn=>
    !(cn.origem.type==='fiber'&&fids.includes(cn.origem.id)) &&
    !(cn.destino.type==='fiber'&&fids.includes(cn.destino.id)));
  state.fiberStrands = state.fiberStrands.filter(f=>f.cableId!==cableId);
  state.cables = state.cables.filter(x=>x.id!==cableId);
  toast('Cabo removido.'); renderDiagram();
}

/* ---- toolbar do diagrama ---- */
function initDiagramToolbar(){
  document.querySelectorAll('#dg-joinsel [data-join]').forEach(b=>{
    b.addEventListener('click', ()=>{
      dgJoinMode = b.getAttribute('data-join');
      document.querySelectorAll('#dg-joinsel [data-join]').forEach(x=>
        x.classList.toggle('active', x.getAttribute('data-join')===dgJoinMode));
      toast('Próximas emendas: '+(dgJoinMode==='fusao'?'fusão':'conectorizadas')+'.');
    });
  });
  document.getElementById('dg-role').addEventListener('click', ()=>{
    isAdmin=!isAdmin; dgSelPin=null; dgSelConn=null; dgSelSplitter=null;
    toast(isAdmin?'Perfil administrador: edição liberada.':'Perfil técnico: somente consulta.');
    renderDiagram();
  });
  document.getElementById('dg-add-splitter').addEventListener('click', ()=>{
    const node=nodeById(diagramNodeId);
    // passo 1: escolher a categoria, para não abrir uma lista enorme de uma vez
    modalEl.innerHTML = `
      <div class="modal-head"><h3>Adicionar splitter</h3><button class="panel-close" id="modal-x">✕</button></div>
      <div class="modal-body">
        <div class="form-field"><label>Categoria</label>
          <select id="f-spl-cat">
            <option value="balanceado">Balanceado (1xN) — atendimento</option>
            <option value="desbalanceado">Desbalanceado (tap) — derivar mantendo o tronco</option>
          </select>
        </div>
        <div class="form-field"><label>Modelo</label><select id="f-spl"></select></div>
        <div id="f-spl-info" class="cable-meta"></div>
      </div>
      <div class="modal-foot"><button class="btn" id="modal-cancel">Cancelar</button>
        <button class="btn btn-accent" id="modal-save">Adicionar</button></div>`;
    overlay.classList.add('show');
    const cat=document.getElementById('f-spl-cat'), lst=document.getElementById('f-spl'), inf=document.getElementById('f-spl-info');
    function preencher(){
      lst.innerHTML = CATALOGO_SPLITTERS[cat.value].map(t=>`<option value="${t}">${t}</option>`).join('');
      mostrarPerda();
    }
    function mostrarPerda(){
      const t=lst.value;
      if(/^1x/.test(t)) inf.textContent = 'Perda de catálogo: -'+(IL_BALANCEADO[t]||0).toFixed(1)+' dB em cada uma das '+vias(t)+' vias.';
      else {
        const p=t.split('/').map(v=>parseInt(v,10));
        inf.textContent = 'Perda de catálogo: -'+perdaPrevista(t,0).toFixed(1)+' dB na via de '+p[0]+'% e -'+perdaPrevista(t,1).toFixed(1)+' dB na via de '+p[1]+'%.';
      }
    }
    cat.addEventListener('change', preencher);
    lst.addEventListener('change', mostrarPerda);
    preencher();
    document.getElementById('modal-x').addEventListener('click', closeModal);
    document.getElementById('modal-cancel').addEventListener('click', closeModal);
    document.getElementById('modal-save').addEventListener('click', ()=>{
      const t=lst.value, bal = cat.value==='balanceado';
      addSplitter(node.id, t, bal?parseInt(t.split('x')[1],10):t.split('/').map(v=>parseInt(v,10)), bal);
      closeModal(); toast('Splitter '+t+' adicionado. Conecte a entrada e as saídas.'); renderDiagram();
    });
  });
  document.getElementById('dg-add-cable').addEventListener('click', ()=>{
    const node=nodeById(diagramNodeId);
    modalEl.innerHTML = `
      <div class="modal-head"><h3>Adicionar cabo à caixa</h3><button class="panel-close" id="modal-x">✕</button></div>
      <div class="modal-body">
        <div class="form-field"><label>Nome</label><input id="f-cnome" type="text" placeholder="CABO ${state.cables.length+1}"></div>
        <div class="form-field"><label>Fibras</label>
          <select id="f-cfo"><option>2</option><option>4</option><option selected>6</option><option>12</option><option>24</option><option>36</option><option>48</option></select></div>
        <div class="form-field"><label>Tipo construtivo</label>
          <select id="f-ctipo"><option>AS (auto-sustentado)</option><option>Drop</option><option>Espinado</option><option>Subterrâneo</option></select></div>
        <div class="cable-meta" style="color:var(--yellow)">O ideal é criar o cabo no mapa (com rota e distância). Criado aqui, ele fica marcado como <b>sem rota</b> até ser georreferenciado.</div>
      </div>
      <div class="modal-foot"><button class="btn" id="modal-cancel">Cancelar</button>
        <button class="btn btn-accent" id="modal-save">Adicionar</button></div>`;
    overlay.classList.add('show');
    document.getElementById('modal-x').addEventListener('click', closeModal);
    document.getElementById('modal-cancel').addEventListener('click', closeModal);
    document.getElementById('modal-save').addEventListener('click', ()=>{
      const nome=document.getElementById('f-cnome').value.trim() || ('CABO '+(state.cables.length+1));
      const fo=parseInt(document.getElementById('f-cfo').value,10);
      const tipoC=document.getElementById('f-ctipo').value;
      const cb=addCable(null, nome, fo+'FO', fo);
      cb.localAtNode=node.id; cb.construtivo=tipoC; cb.semRota=true;
      closeModal(); toast(nome+' adicionado ('+fo+' fibras). Conecte as fibras no diagrama.'); renderDiagram();
    });
  });
}

document.getElementById('diagram-back').addEventListener('click', closeDiagram);

function openDiagram(nodeId){
  diagramNodeId = nodeId;
  dgSelPin=null; dgSelConn=null; dgSelSplitter=null; dgTraceFiber=null;
  dgView={x:0,y:0,k:1};
  document.getElementById('diagram-overlay').classList.add('show');
  renderDiagram();
}
function closeDiagram(){
  document.getElementById('diagram-overlay').classList.remove('show');
  diagramNodeId=null; dgTraceFiber=null;
  renderAll();
}

/* ============ DIAGRAMA ============ */
/* Remove todas as emendas que tocam um pino. Mantém a mesma semântica do
   botão "Excluir conexão" do painel (não devolve a fibra para "livre"). */
function removerConexoesDoPino(key, node){
  const antes = state.connections.length;
  state.connections = state.connections.filter(cn => pinKey(cn.origem)!==key && pinKey(cn.destino)!==key);
  const n = antes - state.connections.length;
  if(!n){ toast('Este ponto não tem emendas.'); return; }
  if(dgSelConn && !state.connections.some(c=>c.id===dgSelConn)) dgSelConn=null;
  dgSelPin=null;
  toast(n===1 ? 'Emenda removida.' : n+' emendas removidas.');
  renderDiagram();
}

/* O clique esquerdo já seleciona o pino, destaca o caminho e, com outro pino
   selecionado, fecha a emenda. A direita só oferece o que o esquerdo não faz:
   escolher o tipo de emenda no ato e desfazer. */
function ctxItemsPino(key, node){
  const ref = refFromKey(key);
  const conectado = isPinConnected(key, node);
  const items = [];
  if(isAdmin && dgSelPin && dgSelPin!==key){
    const a = refFromKey(dgSelPin);
    const erro = validarConexao(a, ref, node);
    items.push({label:'De: '+describeRefTexto(a), disabled:true});
    if(erro){
      items.push({label:erro, disabled:true});
    } else {
      items.push({label:'Emendar por fusão ('+PERDA_FUSAO+' dB)', onClick:()=> criarConexao(a, ref, node, 'fusao')});
      items.push({label:'Emendar por conector ('+PERDA_CONECTOR+' dB)', onClick:()=> criarConexao(a, ref, node, 'conector')});
    }
    items.push({sep:true});
    items.push({label:'Cancelar seleção', onClick:()=>{ dgSelPin=null; renderDiagram(); }});
  } else if(isAdmin && dgSelPin===key){
    items.push({label:'Cancelar seleção', onClick:()=>{ dgSelPin=null; renderDiagram(); }});
  }
  if(isAdmin && conectado){
    if(items.length) items.push({sep:true});
    items.push({label:'Remover emendas deste ponto', danger:true, onClick:()=> removerConexoesDoPino(key, node)});
  }
  if(!items.length)
    items.push({label:'Clique esquerdo para selecionar este ponto', disabled:true});
  return items;
}

function ctxItemsConexao(cn, node){
  const items = [];
  if(!isAdmin){
    items.push({label:'Clique esquerdo para ver as propriedades', disabled:true});
    return items;
  }
  const atual = cn.emenda || 'passante';
  const setEmenda = (v, rotulo)=> ({
    label:(atual===v?'✓ ':'')+rotulo,
    disabled: atual===v,
    onClick:()=>{
      cn.emenda = (v==='passante') ? null : v;
      toast('Emenda alterada para '+rotulo.toLowerCase()+'. Potências recalculadas.');
      renderDiagram();
    }
  });
  items.push(setEmenda('fusao','Fusão ('+PERDA_FUSAO+' dB)'));
  items.push(setEmenda('conector','Conectorizado ('+PERDA_CONECTOR+' dB)'));
  items.push(setEmenda('passante','Passante / sem emenda (0 dB)'));
  items.push({sep:true});
  items.push({label:'Excluir conexão', danger:true, onClick:()=>{
    state.connections = state.connections.filter(x=>x.id!==cn.id);
    if(dgSelConn===cn.id) dgSelConn=null;
    toast('Conexão removida.'); renderDiagram();
  }});
  return items;
}

function ctxItemsSplitter(s, node){
  const items = [];
  if(!isAdmin){
    items.push({label:'Clique esquerdo para ver as propriedades', disabled:true});
    return items;
  }
  items.push({label:'Restaurar posição deste splitter', onClick:()=> restaurarPosBloco(node,'spl:'+s.id)});
  items.push({sep:true});
  items.push({label:'Remover splitter', danger:true, onClick:()=> removerSplitter(s, node)});
  return items;
}

/* Liga, em ordem, as saídas livres do splitter às portas livres da caixa —
   automatiza o clique-clique repetitivo. Sempre conectorizado (papel splitter_out
   já força isso em criarConexao). Não mexe em saída ou porta já ocupada por
   ninguém — só preenche o que sobrou solto dos dois lados. */
function ligarSaidasAsPortasLivres(s, node){
  const outs = s.ports.filter(p=>p.papel==='saida' && !isPinConnected('splitterPort:'+p.id, node));
  const portas = portsOf(node.id).filter(p=>p.status==='livre' && !portaAlimentada(p.id,node))
    .sort((a,b)=> parseInt(a.label.replace('Porta ',''),10) - parseInt(b.label.replace('Porta ',''),10));
  const n = Math.min(outs.length, portas.length);
  for(let i=0;i<n;i++) criarConexao({type:'splitterPort',id:outs[i].id}, {type:'port',id:portas[i].id}, node, 'conector');
  const sobraOuts = outs.length-n, sobraPortas = portas.length-n;
  let msg = n+' saída(s) ligada(s) às portas livres, por conector.';
  if(sobraOuts)   msg += ' '+sobraOuts+' saída(s) do splitter ficaram sem porta.';
  if(sobraPortas) msg += ' '+sobraPortas+' porta(s) livre(s) sobraram sem saída.';
  toast(msg);
  renderDiagram();
}

function restaurarPosBloco(node, key){
  if(node.dgPos && node.dgPos[key]){ delete node.dgPos[key]; renderDiagram(); toast('Posição restaurada.'); }
  else toast('Este bloco já está na posição padrão.');
}

function ctxItemsBloco(key, node){
  const items = [];
  if(key==='ports'){
    const ps = portsOf(node.id);
    items.push({label:ps.filter(p=>p.clientName).length+' de '+ps.length+' portas em uso', disabled:true});
    if(isAdmin) items.push({label:'Restaurar posição do bloco', onClick:()=> restaurarPosBloco(node,key)});
    return items;
  }
  const cb = state.cables.find(c=>c.id===key.slice('cable:'.length));
  if(!cb) return items;
  const fibs = state.fiberStrands.filter(f=>f.cableId===cb.id);
  items.push({label:cb.tipo+' · '+fibs.filter(f=>f.status==='livre').length+'/'+fibs.length+' livres', disabled:true});
  items.push({label:'Origem: '+origemDoCabo(cb, node), disabled:true});
  if(isAdmin){
    items.push({sep:true});
    items.push({label:'Restaurar posição do bloco', onClick:()=> restaurarPosBloco(node,key)});
    items.push({label:'Remover cabo desta caixa', danger:true, onClick:()=> removerCabo(cb.id, node)});
  }
  return items;
}

function ctxItemsDiagramaVazio(node){
  const items = [];
  if(isAdmin){
    items.push({label:'Adicionar cabo', onClick:()=> document.getElementById('dg-add-cable').click()});
    items.push({label:'Adicionar splitter', onClick:()=> document.getElementById('dg-add-splitter').click()});
    items.push({sep:true});
  }
  items.push({label:'Enquadrar diagrama', onClick:()=>{ dgView={x:0,y:0,k:1}; renderDiagram(); }});
  if(isAdmin) items.push({label:'Restaurar posições dos blocos', onClick:()=>{
    node.dgPos={}; dgView={x:0,y:0,k:1}; toast('Posições do diagrama restauradas.'); renderDiagram();
  }});
  if(dgSelPin||dgSelConn||dgSelSplitter||dgTraceFiber)
    items.push({label:'Limpar seleção', onClick:()=>{
      dgSelPin=null; dgSelConn=null; dgSelSplitter=null; dgTraceFiber=null; renderDiagram();
    }});
  return items;
}

function initDiagramCtxMenu(){
  const svgEl = document.getElementById('diagram-svg');
  svgEl.addEventListener('contextmenu',(e)=>{
    e.preventDefault();
    const node = nodeById(diagramNodeId); if(!node) return;
    const t = e.target;
    const pinEl  = t.closest ? t.closest('[data-pin]')  : null;
    const connEl = t.closest ? t.closest('[data-conn]') : null;
    const splEl  = t.closest ? t.closest('[data-spl]')  : null;
    const blockEl= t.closest ? t.closest('[data-block]') : null;

    if(pinEl){
      const key = pinEl.getAttribute('data-pin');
      openCtxMenu(e, describeRefTexto(refFromKey(key)), ctxItemsPino(key, node));
      return;
    }
    if(connEl){
      const cn = state.connections.find(x=>x.id===connEl.getAttribute('data-conn'));
      if(cn) openCtxMenu(e, 'Emenda — '+rotuloTipoConexao(cn.tipo), ctxItemsConexao(cn, node));
      return;
    }
    if(splEl){
      const s = state.splitters.find(x=>x.id===splEl.getAttribute('data-spl'));
      if(s) openCtxMenu(e, 'Splitter '+s.razao, ctxItemsSplitter(s, node));
      return;
    }
    if(blockEl){
      const key = blockEl.getAttribute('data-block');
      const cb = key.indexOf('cable:')===0 ? state.cables.find(c=>c.id===key.slice(6)) : null;
      openCtxMenu(e, cb ? cb.nome : 'Bloco de portas', ctxItemsBloco(key, node));
      return;
    }
    openCtxMenu(e, TYPE_LABEL[node.type]+' — '+node.name, ctxItemsDiagramaVazio(node));
  });
}

/* describeRef() devolve HTML já escapado; para o cabeçalho precisamos de texto puro */
function describeRefTexto(ref){
  const div = document.createElement('div');
  div.innerHTML = describeRef(ref);
  return div.textContent || '';
}

/* Apenas a abertura e a inicialização são usadas pelo mapa. */
return {
  openDiagram,
  init(){
    initDiagramToolbar();
    initDiagramCanvas();
    initDiagramCtxMenu();
  }
};
};
