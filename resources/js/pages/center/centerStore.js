const KEYS={batches:'exo-center-batches',candidates:'exo-center-candidates',pipeline:'exo-center-pipeline'};

const seedBatches=[
  {id:'BAT-2608',code:'MAN-26-08',sector:'Manufacturing',jobRole:'Machine Operator',trainer:'Suresh Sharma',trainerPhone:'9876543201',startDate:'2026-08-01',endDate:'2026-09-30',capacity:50,status:'Ongoing'},
  {id:'BAT-2607',code:'CNC-26-07',sector:'Manufacturing',jobRole:'CNC Operator',trainer:'Meena Joshi',trainerPhone:'9876543202',startDate:'2026-07-15',endDate:'2026-09-15',capacity:35,status:'Completed'},
  {id:'BAT-2609',code:'LOG-26-09',sector:'Logistics',jobRole:'Warehouse Associate',trainer:'Imran Khan',trainerPhone:'9876543203',startDate:'2026-09-01',endDate:'2026-10-30',capacity:40,status:'Ongoing'}
];

const seedCandidates=[
  {id:'EXO-CAN-102548',firstName:'Neha',lastName:'Kumari',whatsapp:'9876543548',district:'Jaipur',qualification:'12th pass',skills:'Machine operation, 5S, measurement tools',industry:'Manufacturing',salary:'15000',available:'Yes',trainingStatus:'Ongoing',batch:'MAN-26-08',batchEnd:'2026-09-30',consent:true,profile:92,createdAt:'2026-09-14T10:20:00Z'},
  {id:'EXO-CAN-102531',firstName:'Farhan',lastName:'Khan',whatsapp:'9999999882',district:'Jaipur',qualification:'ITI',skills:'CNC setup, measurement',industry:'Manufacturing',salary:'18000',available:'Yes',trainingStatus:'Completed',batch:'CNC-26-07',batchEnd:'2026-09-15',consent:true,profile:88,createdAt:'2026-09-13T10:20:00Z'},
  {id:'EXO-CAN-102418',firstName:'Rina',lastName:'Yadav',whatsapp:'9444444721',district:'Jaipur',qualification:'12th pass',skills:'Production line operations',industry:'Manufacturing',salary:'15000',available:'Available after training',trainingStatus:'Ongoing',batch:'MAN-26-08',batchEnd:'2026-09-30',consent:true,profile:84,createdAt:'2026-09-12T10:20:00Z'}
];

function read(key,fallback){try{const saved=JSON.parse(localStorage.getItem(key)||'null');return Array.isArray(saved)?saved:fallback}catch{return fallback}}
export const loadBatches=()=>read(KEYS.batches,seedBatches);
export const saveBatches=rows=>localStorage.setItem(KEYS.batches,JSON.stringify(rows));
export const loadCandidates=()=>read(KEYS.candidates,seedCandidates);
export const saveCandidates=rows=>localStorage.setItem(KEYS.candidates,JSON.stringify(rows));
export const nextCandidateId=rows=>`EXO-CAN-${Math.max(102548,...rows.map(x=>Number(String(x.id).replace(/\D/g,''))||0))+1}`;
export const daysUntil=date=>Math.ceil((new Date(date+'T23:59:59')-new Date())/86400000);
export function batchStage(batch){const now=new Date(),start=new Date(batch.startDate+'T00:00:00'),end=new Date(batch.endDate+'T23:59:59');if(now<start)return'Upcoming';if(now>end)return'Completed';return'Ongoing'}
export function candidateReadiness(candidate){if(candidate.trainingStatus==='Completed'&&candidate.available==='Yes')return'Job ready';const days=daysUntil(candidate.batchEnd);if(candidate.available!=='No'&&days>=0&&days<=15)return'Eligible soon';if(candidate.trainingStatus==='Ongoing')return'In training';return'Not available'}
export const centerIdentity={partner:'Udaan Skills Foundation',center:'ABC Skill Development Center',centerCode:'EXO-CTR-0086'};
