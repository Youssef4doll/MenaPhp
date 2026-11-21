import{a as e}from"./c-43SNA5T5.js";var c=e.object({name:e.string(),nameAr:e.string(),summary:e.string(),summaryAr:e.string(),description:e.string(),descriptionAr:e.string(),specs:e.string(),specsAr:e.string(),keywords:e.string(),seoTitle:e.string().default(""),seoTitleAr:e.string().default(""),seoDescription:e.string().default(""),seoDescriptionAr:e.string().default(""),variants:e.array(e.object({name:e.string(),nameAr:e.string()})),offers:e.array(e.object({label:e.string(),labelAr:e.string()}))}),u=`You write the product texts of Mena, an online shop in Tunisia that sells with cash on delivery. Many customers are older people reading on a phone.

Arabic texts are in Tunisian Arabic (derja), written in Arabic letters, the way Tunisian shops write to their customers: everyday words an older Tunisian understands at once (for example \u062A\u0648\u0651\u0627\u060C \u0628\u0631\u0634\u0627\u060C \u0645\u062A\u0627\u0639\u060C \u0643\u064A\u060C \u0628\u0627\u0634\u060C \u064A\u062A\u0634\u062D\u0646\u060C \u0627\u0644\u0643\u0631\u0637\u0648\u0646\u0629). Not formal Modern Standard Arabic, and not Moroccan, Algerian, Egyptian or Gulf dialect. Product names may keep a common French or English word when that is what Tunisians say (LED, USB-C, s\xE8che-cheveux \u2192 \u0633\u0634\u0648\u0627\u0631).
French texts are plain, correct French.`,h='"keywords": 8 to 20 comma-separated search terms a Tunisian customer might type for this product and that the texts support: synonyms in French, in Tunisian Arabic, in Tunisian written in Latin letters (arabizi, e.g. "lamba" for \u0644\u0645\u0628\u0629), and frequent misspellings. Lower case, no duplicates.',f=`"seoTitle" / "seoTitleAr": the title Google shows, at most 60 characters: first the words a customer types to find this product (what it is), then what sets it apart (size, capacity, use, or the brand when the texts give one). No shop name (the site adds it), no price, no "pas cher", "meilleur", "promo", and no word in capitals for emphasis; brands, models and units keep the capitals the texts give them (MAXISALON, 450 \xB0C, 220 V).
- "seoDescription" / "seoDescriptionAr": the line under that title, 120 to 155 characters: the main benefit and one or two facts from the texts, ending with "Paiement \xE0 la livraison." (in Arabic "\u0627\u0644\u062F\u0641\u0639 \u0639\u0646\u062F \u0627\u0644\u0627\u0633\u062A\u0644\u0627\u0645."). No price, it changes.`,l=`${u}

Rules:
- Translate faithfully. Never add a fact, a number, a feature, a promise, a guarantee or a superlative that the source does not contain. Keep every number, unit, size and brand exactly.
- No exclamation marks, no emojis.
- "specs" fields keep one "label: value" per line, the same lines in the same order.
- Keep paragraph breaks (blank lines) in descriptions.
- ${h}
- ${f}
- Return every field. A field you were not asked to write is returned exactly as given.`,d=t=>t==="to-ar"?"Rewrite every Arabic field (nameAr, summaryAr, descriptionAr, specsAr, seoTitleAr, seoDescriptionAr, variants[].nameAr, offers[].labelAr) from its French counterpart, even when the Arabic is already filled. Write the keywords if they are empty, and the Google title and description in both languages if both are empty, from the product's texts.":"Fill every empty field whose counterpart in the other language is filled, by translating it (French \u2192 Tunisian Arabic, or Tunisian Arabic \u2192 French). Write the keywords if they are empty, and the Google title and description in both languages if both are empty, from the product's texts. Leave filled fields as they are.";function m(t,r){return`${l}

${d(r)}

Answer with the JSON object only, with exactly the same fields as below, and nothing before or after it.

Product texts (JSON):
${JSON.stringify(t,null,2)}`}function g(t){let r=t.indexOf("{"),n=t.lastIndexOf("}");if(r<0||n<=r)return null;try{let a=c.safeParse(JSON.parse(t.slice(r,n+1)));return a.success?a.data:null}catch{return null}}function y(t,r="fill"){return`${l}

${d(r)} Do it for each product of the list below.

Answer with a JSON array only, one object per product, each with its "ref" unchanged and exactly the same fields as given, and nothing before or after the array.

Products (JSON):
${JSON.stringify(t.map(n=>({ref:n.ref,...n.texts})),null,1)}`}function w(t){let r=new Map,n=t.indexOf("["),a=t.lastIndexOf("]");if(n<0||a<=n)return r;let i;try{i=JSON.parse(t.slice(n,a+1))}catch{return r}if(!Array.isArray(i))return r;for(let s of i){if(!s||typeof s!="object"||typeof s.ref!="string")continue;let o=c.safeParse(s);o.success&&r.set(s.ref,o.data)}return r}export{c as a,u as b,h as c,f as d,l as e,d as f,m as g,g as h,y as i,w as j};
