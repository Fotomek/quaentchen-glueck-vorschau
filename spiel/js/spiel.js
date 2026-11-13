(function () {
  "use strict";

  const STORAGE_KEY = "qg-kleeblatt-spiel-v1";
  const COOLDOWN_MS = 12 * 60 * 1000;
  const TEST_COOLDOWN_MS = 800;
  const STUFEN = [0, 30, 80, 160];
  const STUFEN_NAME = ["Keimling", "Zweiblatt", "Dreiblatt", "Vierblatt"];
  const SAETZE = [
    "Manchmal ist es nur ein Quäntchen Glück, das alles verändern kann.",
    "Kleine Gesten. Große Wirkung.",
    "Glück wird größer, wenn man es teilt.",
    "Aus einem Quäntchen Glück kann ein ganzes Stück Hoffnung werden.",
    "Schön, dass du da bist.",
    "Gemeinsam statt einsam."
  ];
  const KARTEN = [
    { id: "tautropfen", titel: "Tautropfen", text: "Dreimal am Morgen gegossen.", check: (s) => s.zaehl.morgenGuss >= 3 },
    { id: "sonnenstrahl", titel: "Sonnenstrahl", text: "Dreimal am Tag Licht geschenkt.", check: (s) => s.zaehl.tagLicht >= 3 },
    { id: "mondlicht", titel: "Mondlicht", text: "Ein Gute-Nacht-Wort hinterlassen.", check: (s) => s.zaehl.nachtFreude >= 1 },
    { id: "blatt1", titel: "Erstes Blatt", text: "Dein Kleeblatt ist da.", check: (s) => s.gestartet },
    { id: "blatt2", titel: "Zweites Blatt", text: "Die Stufe Zweiblatt erreicht.", check: (s) => stufeVon(s.glueck) >= 1 },
    { id: "blatt3", titel: "Drittes Blatt", text: "Die Stufe Dreiblatt erreicht.", check: (s) => stufeVon(s.glueck) >= 2 },
    { id: "blatt4", titel: "Viertes Blatt", text: "Die Stufe Vierblatt erreicht.", check: (s) => stufeVon(s.glueck) >= 3 },
    { id: "kaefer", titel: "Marienkäfer", text: "Zehn liebevolle Gesten.", check: (s) => s.zaehl.aktionen >= 10 },
    { id: "bank", titel: "Ruhige Bank", text: "Fünfmal Zuwendung geschenkt.", check: (s) => s.zaehl.freude >= 5 },
    { id: "wegezeichen", titel: "Wegezeichen", text: "An drei verschiedenen Tagen dagewesen.", check: (s) => s.zaehl.tage.length >= 3 },
    { id: "kranz", titel: "Kleeblatt-Kranz", text: "Wasser, Licht und Freude zugleich voll.", check: (s) => s.zaehl.kranz },
    { id: "gemeinsam", titel: "Gemeinsam statt einsam", text: "Das Vierblatt ist gewachsen.", check: (s) => stufeVon(s.glueck) >= 3 }
  ];

  const params = new URLSearchParams(location.search);
  const IST_TEST = params.get("test") === "1";

  const test = {
    extraMs: 0,
    phase: null,
    freudigBis: 0,
    staunenBis: 0,
    willkommenBis: 0
  };

  function jetzt() {
    return Date.now() + test.extraMs;
  }

  function stufeVon(punkte) {
    let stufe = 0;
    for (let i = 0; i < STUFEN.length; i++) if (punkte >= STUFEN[i]) stufe = i;
    return stufe;
  }

  function phaseVonStunde(h) {
    if (h >= 21 || h < 7) return "nacht";
    if (h >= 19) return "abend";
    if (h >= 7 && h < 10) return "morgen";
    return "tag";
  }

  function aktuellePhase() {
    if (test.phase) return test.phase;
    const d = new Date(jetzt());
    return phaseVonStunde(d.getHours());
  }

  function tagSchluessel(ts) {
    const d = new Date(ts);
    return d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0") + "-" + String(d.getDate()).padStart(2, "0");
  }

  function leerstand() {
    const t = jetzt();
    return {
      version: 1,
      gestartet: false,
      wasser: 78,
      licht: 78,
      freude: 78,
      glueck: 0,
      letzterTick: t,
      letzterBesuch: t,
      cooldown: { wasser: 0, licht: 0, freude: 0 },
      zaehl: {
        wasser: 0,
        licht: 0,
        freude: 0,
        aktionen: 0,
        morgenGuss: 0,
        tagLicht: 0,
        nachtFreude: 0,
        tage: [],
        kranz: false
      },
      karten: {},
      saetze: [],
      wenigerBewegung: false
    };
  }

  function laden() {
    try {
      const roh = localStorage.getItem(STORAGE_KEY);
      if (!roh) return leerstand();
      const s = Object.assign(leerstand(), JSON.parse(roh));
      s.zaehl = Object.assign(leerstand().zaehl, s.zaehl || {});
      s.cooldown = Object.assign(leerstand().cooldown, s.cooldown || {});
      s.karten = s.karten || {};
      s.saetze = s.saetze || [];
      return s;
    } catch (err) {
      console.warn("Spielstand konnte nicht gelesen werden, starte neu.", err);
      return leerstand();
    }
  }

  function speichern() {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(stand));
    } catch (err) {
      console.warn("Spielstand konnte nicht gespeichert werden.", err);
    }
  }

  function klemmen(n) {
    return Math.max(0, Math.min(100, n));
  }

  function verfallen(stand) {
    const t = jetzt();
    const delta = Math.max(0, t - (stand.letzterTick || t));
    if (delta < 1000) {
      stand.letzterTick = t;
      return;
    }
    const stunden = delta / 3600000;
    // Zerlege grob in Stunden und wende je Stunde die Passrate der damaligen Phase an.
    let rest = stunden;
    let cursor = stand.letzterTick;
    while (rest > 0) {
      const schritt = Math.min(rest, 1);
      const h = new Date(cursor).getHours();
      const nacht = test.phase ? test.phase === "nacht" : (h >= 21 || h < 7);
      const fW = nacht ? 1 : 5;
      const fL = nacht ? 0.3 : 4;
      const fF = nacht ? 0.8 : 3;
      stand.wasser = klemmen(stand.wasser - fW * schritt);
      stand.licht = klemmen(stand.licht - fL * schritt);
      stand.freude = klemmen(stand.freude - fF * schritt);
      cursor += schritt * 3600000;
      rest -= schritt;
    }
    stand.letzterTick = t;
  }

  function stimmungVon(stand) {
    const t = jetzt();
    if (test.willkommenBis > t) return "willkommen";
    if (test.staunenBis > t) return "staunt";
    if (test.freudigBis > t) return "freudig";
    const phase = aktuellePhase();
    if (phase === "nacht") return "schlaeft";
    const weg = t - (stand.letzterBesuch || t);
    if (stand.wasser < 28 && stand.licht < 28 && stand.freude < 35 || (weg > 18 * 3600000 && stand.wasser < 40)) {
      if (stand.wasser < 40 || stand.licht < 40 || stand.freude < 40) return "sehnt";
    }
    if (stand.wasser < 30) return "durstig";
    if (stand.licht < 30) return "braucht-licht";
    if (stand.freude < 30) return "zuwendung";
    if (phase === "abend") return "muede";
    if (stand.wasser >= 75 && stand.licht >= 75 && stand.freude >= 75) return "gluecklich";
    return "zufrieden";
  }

  const $ = (id) => document.getElementById(id);
  let stand = laden();
  let spruchTimer = 0;

  function spruch(text, art) {
    const box = $("spruch");
    if (!text) {
      box.innerHTML = "";
      return;
    }
    const cls = art === "satz" ? "satz" : "satz alltag";
    box.innerHTML = '<span class="' + cls + '">' + text + "</span>";
    clearTimeout(spruchTimer);
    spruchTimer = setTimeout(function () {
      if (!IST_TEST || art === "satz") {
        /* Alltagszeilen dürfen länger bleiben, Sätze auch. Nacht-Hinweis bleibt bis zur nächsten Aktion. */
      }
    }, 8000);
  }

  function kartenPruefen() {
    let neu = null;
    KARTEN.forEach(function (k) {
      if (!stand.karten[k.id] && k.check(stand)) {
        stand.karten[k.id] = jetzt();
        if (!neu) neu = k;
      }
    });
    return neu;
  }

  function tagMerken() {
    const key = tagSchluessel(jetzt());
    if (stand.zaehl.tage.indexOf(key) === -1) stand.zaehl.tage.push(key);
  }

  function zeichnen() {
    const phase = aktuellePhase();
    document.documentElement.setAttribute("data-phase", phase);
    document.documentElement.setAttribute("data-bewegung", stand.wenigerBewegung ? "aus" : "an");
    $("opt-bewegung").checked = !!stand.wenigerBewegung;

    const stufe = stufeVon(stand.glueck);
    const stimm = stimmungVon(stand);
    const klee = $("klee");
    klee.setAttribute("data-stufe", String(stufe));
    klee.setAttribute("data-stimmung", stimm);
    $("klee-desc").textContent =
      "dein Kleeblatt, " + STUFEN_NAME[stufe] + ", Stimmung: " + stimm.replace("-", " ");

    $("untertitel").textContent = "dein Kleeblatt · " + STUFEN_NAME[stufe];
    $("glueck-zahl").textContent = String(Math.floor(stand.glueck));

    [
      ["wasser", stand.wasser],
      ["licht", stand.licht],
      ["freude", stand.freude]
    ].forEach(function (paar) {
      const el = $("wert-" + paar[0]);
      const v = Math.round(paar[1]);
      el.querySelector(".wert-zahl").textContent = v + " %";
      el.querySelector(".leiste > span").style.width = v + "%";
      el.setAttribute("data-niedrig", v < 30 ? "1" : "0");
    });

    const cool = IST_TEST ? TEST_COOLDOWN_MS : COOLDOWN_MS;
    const t = jetzt();
    ["wasser", "licht", "freude"].forEach(function (k) {
      const btn = $("btn-" + k);
      const sperre = t < (stand.cooldown[k] || 0);
      btn.disabled = sperre;
    });

    if (phase === "nacht") {
      $("btn-freude").textContent = "Gute Nacht";
    } else {
      $("btn-freude").textContent = "Zuwendung";
    }

    const startScreen = $("screen-willkommen");
    if (!stand.gestartet) {
      startScreen.hidden = false;
    }
  }

  function sterneBauen() {
    const box = $("sterne");
    box.innerHTML = "";
    for (let i = 0; i < 18; i++) {
      const s = document.createElement("span");
      s.className = "stern";
      s.style.left = 6 + Math.random() * 88 + "%";
      s.style.top = 4 + Math.random() * 46 + "%";
      s.style.opacity = String(0.4 + Math.random() * 0.6);
      box.appendChild(s);
    }
  }

  function rueckkehrPruefen() {
    if (!stand.gestartet) return;
    const weg = jetzt() - (stand.letzterBesuch || jetzt());
    if (weg > 18 * 3600000) {
      test.willkommenBis = jetzt() + 5000;
      spruch("Schön, dass du wieder da bist.", "alltag");
    } else if (aktuellePhase() === "nacht") {
      spruch("Schlaf gut.", "alltag");
    }
    stand.letzterBesuch = jetzt();
  }

  function aktion(art) {
    if (!stand.gestartet) return;
    verfallen(stand);
    const t = jetzt();
    const cool = IST_TEST ? TEST_COOLDOWN_MS : COOLDOWN_MS;
    if (t < (stand.cooldown[art] || 0)) {
      spruch("Ich bin gerade satt, komm später wieder.", "alltag");
      return;
    }
    const phase = aktuellePhase();
    const vorher = { wasser: stand.wasser, licht: stand.licht, freude: stand.freude };
    if (art === "wasser") stand.wasser = klemmen(stand.wasser + 42);
    if (art === "licht") stand.licht = klemmen(stand.licht + 42);
    if (art === "freude") stand.freude = klemmen(stand.freude + 42);

    stand.zaehl[art] += 1;
    stand.zaehl.aktionen += 1;
    if (art === "wasser" && phase === "morgen") stand.zaehl.morgenGuss += 1;
    if (art === "licht" && (phase === "tag" || phase === "morgen")) stand.zaehl.tagLicht += 1;
    if (art === "freude" && phase === "nacht") stand.zaehl.nachtFreude += 1;
    if (stand.wasser >= 99 && stand.licht >= 99 && stand.freude >= 99) stand.zaehl.kranz = true;
    tagMerken();

    let plus = 5;
    if (stand.wasser >= 60 && stand.licht >= 60 && stand.freude >= 60) plus += 3;
    const alteStufe = stufeVon(stand.glueck);
    stand.glueck += plus;
    const neueStufe = stufeVon(stand.glueck);

    stand.cooldown[art] = t + cool;
    if (phase !== "nacht") {
      test.staunenBis = t + 450;
      test.freudigBis = t + 1600;
    } else {
      test.staunenBis = 0;
      test.freudigBis = 0;
    }

    let text;
    let artSpruch = "alltag";
    if (art === "freude") {
      const satz = SAETZE[stand.saetze.length % SAETZE.length];
      if (stand.saetze.indexOf(satz) === -1) stand.saetze.push(satz);
      text = satz;
      artSpruch = "satz";
      if (phase === "nacht") text = "Schlaf gut. " + satz;
    } else if (art === "wasser") {
      text = "Frisch und wach. Danke fürs Gießen.";
    } else {
      text = "Das Licht tut gut.";
    }
    if (neueStufe > alteStufe) {
      text = "Dein Kleeblatt ist jetzt ein " + STUFEN_NAME[neueStufe] + ".";
      artSpruch = "alltag";
    }
    const neueKarte = kartenPruefen();
    if (neueKarte && neueStufe === alteStufe) {
      text = "Neu im Glücksbuch: " + neueKarte.titel + ".";
    }
    spruch(text, artSpruch);
    stand.letzterBesuch = t;
    speichern();
    zeichnen();
  }

  function buchFuellen() {
    const offen = Object.keys(stand.karten).length;
    $("buch-lead").textContent =
      "Glückspunkte: " + Math.floor(stand.glueck) + ". Stufe: " + STUFEN_NAME[stufeVon(stand.glueck)] +
      ". " + offen + " von " + KARTEN.length + " Karten.";
    const box = $("karten");
    box.innerHTML = "";
    KARTEN.forEach(function (k) {
      const da = !!stand.karten[k.id];
      const el = document.createElement("article");
      el.className = "karte";
      el.setAttribute("data-offen", da ? "1" : "0");
      el.innerHTML = "<h3></h3><p></p>";
      el.querySelector("h3").textContent = k.titel;
      el.querySelector("p").textContent = da ? k.text : (naheAn(k) ? k.text : "Noch eine kleine Geste, dann ist die Karte da.");
      box.appendChild(el);
    });
    const liste = $("saetze");
    liste.innerHTML = "";
    if (!stand.saetze.length) {
      const li = document.createElement("li");
      li.textContent = "Noch kein Glückssatz. Schenk deinem Kleeblatt ein wenig Zuwendung.";
      li.style.fontFamily = "Asap, sans-serif";
      liste.appendChild(li);
    } else {
      stand.saetze.forEach(function (s) {
        const li = document.createElement("li");
        li.textContent = s;
        liste.appendChild(li);
      });
    }
  }

  function naheAn(karte) {
    // Offene, ehrliche Nähe: Bedingungstext schon zeigen, wenn der Zähler nicht mehr bei null ist.
    if (karte.id === "tautropfen") return stand.zaehl.morgenGuss >= 1;
    if (karte.id === "sonnenstrahl") return stand.zaehl.tagLicht >= 1;
    if (karte.id === "mondlicht") return stand.zaehl.nachtFreude >= 0 && aktuellePhase() === "nacht";
    if (karte.id === "kaefer") return stand.zaehl.aktionen >= 4;
    if (karte.id === "bank") return stand.zaehl.freude >= 2;
    if (karte.id === "wegezeichen") return stand.zaehl.tage.length >= 1;
    if (karte.id === "kranz") return stand.wasser >= 80 && stand.licht >= 80 && stand.freude >= 80;
    return false;
  }

  function screen(name, an) {
    $("screen-buch").hidden = true;
    $("screen-info").hidden = true;
    if (!stand.gestartet) $("screen-willkommen").hidden = false;
    if (name === "buch" && an) {
      buchFuellen();
      $("screen-buch").hidden = false;
    }
    if (name === "info" && an) $("screen-info").hidden = false;
    if (name === "wiese") {
      $("screen-buch").hidden = true;
      $("screen-info").hidden = true;
    }
  }

  function starten() {
    stand.gestartet = true;
    stand.letzterBesuch = jetzt();
    stand.letzterTick = jetzt();
    tagMerken();
    kartenPruefen();
    speichern();
    $("screen-willkommen").hidden = true;
    spruch("Hallo, ich bin dein Kleeblatt.", "alltag");
    zeichnen();
  }

  function resetStand() {
    test.extraMs = 0;
    test.phase = IST_TEST ? test.phase : null;
    test.freudigBis = 0;
    test.staunenBis = 0;
    test.willkommenBis = 0;
    stand = leerstand();
    speichern();
    $("screen-buch").hidden = true;
    $("screen-info").hidden = true;
    $("screen-willkommen").hidden = false;
    spruch("");
    zeichnen();
  }

  /* Ereignisse */
  $("btn-start").addEventListener("click", starten);
  $("btn-wasser").addEventListener("click", function () { aktion("wasser"); });
  $("btn-licht").addEventListener("click", function () { aktion("licht"); });
  $("btn-freude").addEventListener("click", function () { aktion("freude"); });
  $("btn-buch").addEventListener("click", function () { screen("buch", true); });
  $("btn-info").addEventListener("click", function () { screen("info", true); });
  function zuWiese() { screen("wiese", true); }
  $("btn-buch-zu").addEventListener("click", zuWiese);
  $("btn-buch-zu-unten").addEventListener("click", zuWiese);
  $("btn-info-zu").addEventListener("click", zuWiese);
  $("btn-info-zu-unten").addEventListener("click", zuWiese);
  $("opt-bewegung").addEventListener("change", function () {
    stand.wenigerBewegung = $("opt-bewegung").checked;
    speichern();
    zeichnen();
    blinzelnPlanen();
  });
  $("btn-reset-info").addEventListener("click", function () {
    if (window.confirm("Soll der Spielstand auf diesem Gerät wirklich zurückgesetzt werden?")) resetStand();
  });

  $("figur-wrap").addEventListener("pointerdown", function (ev) {
    if (!stand.gestartet) return;
    if (ev.target.closest && ev.target.closest("button")) return;
    /* Kurze Reaktion: staunen, dann lachen. In der Nacht bleibt das Kleeblatt im Schlaf. */
    if (aktuellePhase() === "nacht") return;
    const t = jetzt();
    test.staunenBis = t + 450;
    test.freudigBis = t + 1500;
    zeichnen();
  });

  let streich = 0;
  $("figur-wrap").addEventListener("pointermove", function (ev) {
    if (!stand.gestartet || ev.buttons === 0) return;
    streich += Math.abs(ev.movementX || 0) + Math.abs(ev.movementY || 0);
    if (streich > 80) {
      streich = 0;
      aktion("freude");
    }
  });
  $("figur-wrap").addEventListener("pointerup", function () { streich = 0; });

  /* Testleiste */
  if (IST_TEST) {
    const leiste = $("testleiste");
    leiste.hidden = false;
    $("app").classList.add("test-aktiv");
    leiste.addEventListener("click", function (ev) {
      const btn = ev.target.closest("button");
      if (!btn) return;
      const art = btn.getAttribute("data-test");
      if (art === "phase") {
        test.phase = btn.getAttribute("data-wert");
        if (test.phase === "nacht") spruch("Schlaf gut.", "alltag");
        else spruch("Hallo, ich bin dein Kleeblatt.", "alltag");
        zeichnen();
      }
      if (art === "spulen") {
        const ms = Number(btn.getAttribute("data-ms"));
        test.extraMs += ms;
        verfallen(stand);
        stand.letzterBesuch = stand.letzterBesuch; // unverändert, damit Rückkehr greifen kann
        // Für +2 Tage die letzte Besuchzeit in der Vergangenheit lassen
        if (ms >= 172800000) {
          /* letzterBesuch bleibt alt relativ zur neuen „jetzt“-Zeit, weil extraMs steigen und letzterBesuch unverändert ist. */
          test.willkommenBis = jetzt() + 4000;
          spruch("Schön, dass du wieder da bist.", "alltag");
        }
        speichern();
        zeichnen();
      }
      if (art === "werte") {
        const w = btn.getAttribute("data-wert");
        if (w === "voll") {
          stand.wasser = 100; stand.licht = 100; stand.freude = 100;
          stand.zaehl.kranz = true;
        } else if (w === "mittel") {
          stand.wasser = 58; stand.licht = 60; stand.freude = 62;
        } else {
          stand.wasser = 18; stand.licht = 16; stand.freude = 22;
          spruch("", "alltag");
        }
        kartenPruefen();
        speichern();
        zeichnen();
      }
      if (art === "glueck") {
        const n = Number(btn.getAttribute("data-wert"));
        if (n === 160) stand.glueck = Math.max(stand.glueck, 160);
        else stand.glueck += n;
        kartenPruefen();
        speichern();
        zeichnen();
      }
      if (art === "willkommen") {
        stand.gestartet = false;
        speichern();
        $("screen-buch").hidden = true;
        $("screen-info").hidden = true;
        $("screen-willkommen").hidden = false;
        zeichnen();
      }
      if (art === "reset") resetStand();
    });
  }

  function bewegungReduziert() {
    return !!stand.wenigerBewegung || window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  }

  let blinkTimer = 0;
  function blinzelnPlanen() {
    clearTimeout(blinkTimer);
    const klee = $("klee");
    if (klee) klee.classList.remove("is-blinzeln");
    if (IST_TEST || bewegungReduziert()) return;
    const wait = 2800 + Math.random() * 2500;
    blinkTimer = setTimeout(function () {
      const stimm = stimmungVon(stand);
      if (stimm === "schlaeft" || bewegungReduziert()) {
        blinzelnPlanen();
        return;
      }
      klee.classList.add("is-blinzeln");
      const zu = 100 + Math.random() * 50;
      setTimeout(function () {
        klee.classList.remove("is-blinzeln");
        const auf = 80 + Math.random() * 40;
        setTimeout(blinzelnPlanen, auf);
      }, zu);
    }, wait);
  }

  /* Start */
  sterneBauen();
  verfallen(stand);
  if (stand.gestartet) {
    $("screen-willkommen").hidden = true;
    rueckkehrPruefen();
  } else {
    $("screen-willkommen").hidden = false;
  }
  kartenPruefen();
  speichern();
  zeichnen();
  blinzelnPlanen();

  setInterval(function () {
    verfallen(stand);
    zeichnen();
  }, IST_TEST ? 2000 : 60000);
})();
