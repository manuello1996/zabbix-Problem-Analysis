document.addEventListener('DOMContentLoaded', () => {
    class AnalistProblem {
        constructor() {
            this.pendingUpdate = null;
            this.events = new AbortController();
            this.observer = new MutationObserver(records => {
                window.AnalistProblemPopup?.cleanupDetached();
                const selector = '.flickerfreescreen, .dashboard-widget-problems';
                const relevant = records.some(record => {
                    if (record.target instanceof Element && record.target.closest(selector)) return true;
                    return [...record.addedNodes, ...record.removedNodes].some(node =>
                        node instanceof Element && (node.matches(selector) || node.querySelector(selector)));
                });
                if (relevant) this.scheduleUpdate();
            });
            this.init();
        }

        init() {
            this.addLTSButton();
            this.observer.observe(document.body, {childList: true, subtree: true});
            document.addEventListener('zbx_reload', () => this.scheduleUpdate(), {signal: this.events.signal});
            window.addEventListener('pagehide', () => this.suspend(), {signal: this.events.signal});
            window.addEventListener('pageshow', event => {
                if (event.persisted) {
                    this.observer.observe(document.body, {childList: true, subtree: true});
                    this.scheduleUpdate();
                }
            }, {signal: this.events.signal});
        }

        scheduleUpdate() {
            clearTimeout(this.pendingUpdate);
            this.pendingUpdate = setTimeout(() => {
                this.pendingUpdate = null;
                this.addLTSButton();
            }, 80);
        }

        suspend() {
            clearTimeout(this.pendingUpdate);
            this.pendingUpdate = null;
            this.observer.disconnect();
        }

        destroy() {
            this.suspend();
            this.events.abort();
        }

        addLTSButton() {
            // Adiciona botões na lista principal de problemas
            this.addLTSButtonToMainTable();

            // Adiciona botões nos widgets da dashboard
            this.addLTSButtonToDashboardWidgets();
        }

        addLTSButtonToMainTable() {
            const screen = document.querySelector('.flickerfreescreen');
            if (!screen) return;
            screen.querySelectorAll('table.list-table').forEach(table => this.decorateProblemTable(table));
        }

        addLTSButtonToDashboardWidgets() {
            document.querySelectorAll('.dashboard-widget-problems table.list-table')
                .forEach(table => this.decorateProblemTable(table, true));
        }

        decorateProblemTable(table, isWidget = false) {
            const tbody = table.querySelector('tbody');
            const header = table.querySelector('thead tr');
            if (!tbody || !header) return;
            const rows = Array.from(tbody.rows);
            const problems = rows.map(row => ({row, data: this.extractProblemDataFromMenuPopup(row)}))
                .filter(({row, data}) => data && data.eventid
                    && (isWidget || row.querySelector('[data-menu-popup*="triggerid"]')));
            // Verify that the table contains problems before adding a column.
            if (!problems.length && !header.querySelector('.lts-header')) return;
            if (!header.querySelector('.lts-header')) {
                const cell = document.createElement('th');
                cell.className = 'lts-header';
                cell.textContent = 'Details';
                header.appendChild(cell);
            }
            problems.forEach(({row, data}) => this.addLTSColumnToRow(row, data, isWidget));
            const columnCount = Array.from(header.cells).reduce((sum, cell) => sum + cell.colSpan, 0);
            rows.forEach(row => {
                if (row.querySelector('.lts-column-cell') || !row.cells.length) return;
                const count = Array.from(row.cells).reduce((sum, cell) => sum + cell.colSpan, 0);
                if (count !== columnCount - 1) return;
                // Timeline separators and empty-state rows usually end in a spanning cell.
                const last = row.cells[row.cells.length - 1];
                if (last.colSpan > 1 || row.cells.length === 1) {
                    last.colSpan++;
                } else {
                    const spacer = document.createElement('td');
                    spacer.className = 'lts-column-cell';
                    row.appendChild(spacer);
                }
            });
        }

        extractProblemDataFromMenuPopup(row) {
            let eventid = row.getAttribute('data-eventid');
            let triggerid = null;
            let hostid = null;
            let hostname = '';
            let problemName = '';

            try {
                // Extrai eventid de links ou atributos
                const eventLink = row.querySelector('a[href*="eventid"]');
                if (eventLink) {
                    const match = eventLink.href.match(/eventid=(\d+)/);
                    if (match) {
                        eventid = match[1];
                    }
                }

                // Extrai hostid e triggerid usando data-menu-popup (método mais confiável)
                const hostElement = row.querySelector('[data-menu-popup*="hostid"]');
                const triggerElement = row.querySelector('[data-menu-popup*="triggerid"]');

                if (hostElement) {
                    try {
                        const hostData = JSON.parse(hostElement.getAttribute('data-menu-popup'));
                        if (hostData.data && hostData.data.hostid) {
                            hostid = hostData.data.hostid;
                            hostname = hostElement.textContent.trim();
                        }
                    } catch (e) {
                        console.warn('Erro ao parsear hostid:', e);
                    }
                }

                if (triggerElement) {
                    try {
                        const triggerData = JSON.parse(triggerElement.getAttribute('data-menu-popup'));
                        if (triggerData.data && triggerData.data.triggerid) {
                            triggerid = triggerData.data.triggerid;
                            problemName = triggerElement.textContent.trim();
                            eventid = eventid || triggerData.data.eventid;
                        }
                    } catch (e) {
                        console.warn('Erro ao parsear triggerid:', e);
                    }
                }

                // Se não conseguiu extrair pelos data-menu-popup, tenta métodos alternativos
                if (!hostid || !triggerid) {
                    const fallbackData = this.extractProblemDataFallback(row);
                    hostid = hostid || fallbackData.hostid;
                    triggerid = triggerid || fallbackData.triggerid;
                    hostname = hostname || fallbackData.hostname;
                    problemName = problemName || fallbackData.problemName;
                }

                //console.log('AnalistProblem - Dados extraídos via menu-popup:', {
                //    eventid: eventid,
                //    triggerid: triggerid,
                //    hostid: hostid,
                //    hostname: hostname,
                //    problemName: problemName
                //});

                return eventid ? {
                    eventid: eventid,
                    triggerid: triggerid,
                    hostid: hostid,
                    hostname: hostname,
                    problemName: problemName
                } : null;

            } catch (error) {
                //console.log('Erro ao extrair dados via menu-popup:', error);
                return null;
            }
        }

        extractProblemDataFallback(row) {
            let eventid = null;
            let triggerid = null;
            let hostid = null;
            let hostname = '';
            let problemName = '';

            try {
                // Tenta extrair eventid de links ou atributos
                const eventLink = row.querySelector('a[href*="eventid"]');
                if (eventLink) {
                    const match = eventLink.href.match(/eventid=(\d+)/);
                    if (match) {
                        eventid = match[1];
                    }
                }

                // Múltiplas tentativas para extrair triggerid
                let triggerLink = row.querySelector('a[href*="triggerids"]');
                if (triggerLink) {
                    const match = triggerLink.href.match(/triggerids.*?(\d+)/);
                    if (match) {
                        triggerid = match[1];
                    }
                }

                // Tenta também por triggers no singular
                if (!triggerid) {
                    triggerLink = row.querySelector('a[href*="triggerid"]');
                    if (triggerLink) {
                        const match = triggerLink.href.match(/triggerid.*?(\d+)/);
                        if (match) {
                            triggerid = match[1];
                        }
                    }
                }

                // Múltiplas tentativas para extrair hostid e hostname
                // 1. Procura por links com hostid na URL
                let hostLink = row.querySelector('a[href*="hostid"]');
                if (!hostLink) {
                    hostLink = row.querySelector('a[href*="filter_hostids"]');
                }
                if (!hostLink) {
                    hostLink = row.querySelector('a[href*="hostids"]');
                }

                if (hostLink) {
                    const hostMatch = hostLink.href.match(/(?:hostids?|filter_hostids).*?(\d+)/);
                    if (hostMatch) {
                        hostid = hostMatch[1];
                    }
                    hostname = hostLink.textContent.trim();
                }

                // 2. Se não encontrou, procura na coluna Host (geralmente 4ª ou 5ª coluna)
                if (!hostid || !hostname) {
                    const hostCells = row.querySelectorAll('td');
                    for (let i = 3; i <= 5 && i < hostCells.length; i++) {
                        const hostCell = hostCells[i];
                        const hostAnchor = hostCell.querySelector('a');
                        if (hostAnchor && hostAnchor.href.includes('hostid')) {
                            const match = hostAnchor.href.match(/hostid.*?(\d+)/);
                            if (match) {
                                hostid = match[1];
                                hostname = hostAnchor.textContent.trim();
                                break;
                            }
                        }
                    }
                }

                // 3. Se ainda não encontrou, tenta por data attributes ou outros padrões
                if (!hostid) {
                    // Procura por data-hostid no row ou elementos filhos
                    const elementWithHostid = row.querySelector('[data-hostid]');
                    if (elementWithHostid) {
                        hostid = elementWithHostid.getAttribute('data-hostid');
                    }
                }

                // 4. Se ainda não tem hostname, procura por texto que pode ser hostname
                if (!hostname && hostid) {
                    // Procura na célula que contém informações do host
                    const possibleHostCell = row.querySelector('td:nth-child(4), td:nth-child(5)');
                    if (possibleHostCell) {
                        const text = possibleHostCell.textContent.trim();
                        if (text && !text.includes('Problem') && text.length > 0) {
                            hostname = text;
                        }
                    }
                }

                // Extrai nome do problema
                const problemCell = row.querySelector('td:nth-child(3), td:nth-child(4)');
                if (problemCell) {
                    problemName = problemCell.textContent.trim();
                }

                // Se não conseguiu extrair eventid, tenta de outra forma
                if (!eventid) {
                    // Procura por qualquer link que contenha números que possam ser eventid
                    const allLinks = row.querySelectorAll('a[href*="action="]');
                    allLinks.forEach(link => {
                        const href = link.href;
                        if (href.includes('eventid')) {
                            const match = href.match(/eventid=(\d+)/);
                            if (match && !eventid) {
                                eventid = match[1];
                            }
                        }
                    });
                }

            } catch (error) {
                //console.log('Erro ao extrair dados da linha:', error);
                return null;
            }

            // Debug: log dos dados extraídos
            //console.log('AnalistProblem - Dados extraídos da linha:', {
            //    eventid: eventid,
            //    triggerid: triggerid,
            //    hostid: hostid,
            //    hostname: hostname,
            //    problemName: problemName
            //});

            return eventid ? {
                eventid: eventid,
                triggerid: triggerid,
                hostid: hostid,
                hostname: hostname,
                problemName: problemName
            } : null;
        }

        addLTSColumnToRow(row, problemData, isWidget = false) {
            // Verifica se já existe a coluna LTS nesta linha
            const existingButton = row.querySelector('.js-lts-button') || row.querySelector('.js-lts-button-widget');
            if (existingButton) return;

            // Cria o botão
            const button = document.createElement('button');
            button.type = 'button';
            const spacer = row.querySelector('.lts-column-cell');
            if (spacer) spacer.remove();
            button.className = isWidget ? 'btn-alt js-lts-button-widget' : 'btn-alt js-lts-button';
            button.innerHTML = 'Details';
            button.title = `Detail for: ${problemData.hostname} - ${problemData.problemName}`;

            // Cria a nova célula da tabela
            const td = document.createElement('td');
            td.className = 'lts-column-cell';
            td.appendChild(button);

            // Adiciona a célula no final da linha
            row.appendChild(td);

            // Adiciona evento de click
            button.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.openLTSModal(problemData);
            });
        }

        addButtonToRow(row, problemData) {
            // Encontra a última célula da linha para adicionar o botão
            const lastCell = row.lastElementChild;
            if (!lastCell) return;

            // Cria o botão
            const button = document.createElement('button');
            button.className = 'analist-lts-btn btn-link';
            button.innerHTML = 'LTS';
            button.title = 'Problem analysis';

            // Adiciona evento de click
            button.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.openLTSModal(problemData);
            });

            // Adiciona o botão à célula
            lastCell.appendChild(button);
        }

        openLTSModal(problemData) {
            //console.log('AnalistProblem - Abrindo modal com dados:', problemData);

            const params = new URLSearchParams({
                eventid: problemData.eventid,
                ...(problemData.triggerid && { triggerid: problemData.triggerid }),
                ...(problemData.hostid && { hostid: problemData.hostid }),
                ...(problemData.hostname && { hostname: problemData.hostname }),
                ...(problemData.problemName && { problem_name: problemData.problemName })
            });

            // URL do endpoint do nosso módulo
            const url = params.toString();
            //console.log('AnalistProblem - URL construída:', url);

            // Abre popup usando a função do Zabbix
            if (typeof PopUp !== 'undefined') {
                PopUp('problemanalist.view', url, {
                    dialogue_class: 'modal-popup-large',
                    draggable: true,
                    resizable: true
                });
            } else {
                // Fallback para abrir em nova janela
                window.open('zabbix.php?action=problemanalist.view&' + url, 'analist-lts', 'width=1200,height=800,scrollbars=yes,resizable=yes');
            }
        }


    }

    // Inicializa a classe
    new AnalistProblem();
});
