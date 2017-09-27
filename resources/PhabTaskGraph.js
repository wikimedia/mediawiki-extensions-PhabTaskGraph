var phabtaskgraph_controller = ( function ( mw, $ ) {
	'use strict';

	return {
		initialize: function () {
			var graph = mw.config.get( 'PhabTaskGraphConfig' );
			this.showGraph(graph.id, graph.selected_tasks, graph.selected_projects,
				graph.nodes, graph.links, graph.projects, graph.people, graph.url,
				graph.width, graph.height);
		},
		showGraph: function (id, selected_tasks, selected_projects, nodes, links,
			projects, people, url, width, height) {
			var radius = 8;
			var padding = 5;

			var selectedNode = -1;
			var linkedByIndex = {};
			links.forEach(function (d) {
				linkedByIndex[d.source + ',' + d.target] = 1;
			});

			var force = d3
				.layout
				.force()
				.charge(-400)
				.linkDistance(120)
				.size([width, height]);

			var div = d3
				.select('#' + id);

			var tooltip = div
				.append('div')
				.attr('class', 'phabtaskgraph-tooltip')
				.style('opacity', 0);

			var zoom = d3
				.behavior
				.zoom()
				.scaleExtent([0.5,5])
				.on('zoom', zoomed);

			var svg = div
				.append('svg')
				.attr('width', width)
				.attr('height', height)
				.attr('class', 'phabtaskgraph-graph')
				.append('g')
				.call(zoom);

			svg
				.on('click', function() {
					if (d3.event.altKey) {
						clearHighlight()
					}
				});

			svg
				.on('dblclick.zoom', null);

			svg
				.append('defs')
				.selectAll('marker')
				.data(['arrow'])
				.enter()
				.append('marker')
				.attr('id', function (d) {
					return d;
				})
				.attr('viewBox', '0 0 10 10')
				.attr('refX', 20)
				.attr('refY', 5)
				.attr('markerWidth', 8)
				.attr('markerHeight', 8)
				.attr('orient', 'auto')
				.append('path')
				.attr('d', 'M 0 0 L 10 5 L 0 10 z')
				.attr('class', 'phabtaskgraph-marker');

			var rect = svg
				.append('rect')
				.attr('width', width)
				.attr('height', height)
				.style('fill', 'none')
				.style('pointer-events', 'all');

			var container = svg.append('g');

			var link = container
				.selectAll('.link')
				.data(links)
				.enter()
				.append('line')
				.attr('class', 'phabtaskgraph-link')
				.style('marker-end', 'url(#arrow)');

			var node = container
				.selectAll('.node')
				.data(nodes)
				.enter()
				.append('g')
				.attr('class', 'phabtaskgraph-node');

			node
				.append('circle')
				.attr('r', radius - 0.75)
				.style('fill', function (d) {
					return d.color;
				})
				.style('stroke', function (d) {
					if (selected_tasks.includes(String(d.id))) {
						return '#00f';
					}
					var key;
					for (key in d.projects) {
						if (selected_projects.includes(key)) {
							return '#00f';
						}
					}
					return '#fff';
				});

			node
				.append('text')
				.attr('dx', 10)
				.attr('dy', '.35em')
				.text(function (d) {
					var name = d.name;
					if (name.length > 30) {
						name = name.substring(0,30) + '...';
					}
					return name;
				})
				.attr('class', 'phabtaskgraph-text');

			node
				.on('dblclick', function (d) {
					window.open(url + '/' + d.taskid, '_blank');
				});

			node
				.on('click', function (d) {
					if (d3.event.altKey) {
						highlightConnected(d);
						d3.event.stopPropagation();
					}
				});

			var dragging = false;
			var paused = false;

			node
				.on('mouseover', function (d) {
					if (force.alpha()) {
						force.stop();
						paused = true;
					}
					tooltip
						.transition()
						.duration(200)
						.style('opacity', .9);
					tooltip
						.html(format_tooltip(d))
						.style("left", d3.event.offsetX + "px")
						.style("top", (d3.event.offsetY - 28) + "px");
				});

			node
				.on('mouseout', function (d) {
					if (!dragging && paused) {
						force.resume();
						paused = false;
					}
					tooltip
						.transition()
						.duration(500)
						.style('opacity', 0);
				});

			var drag = d3
				.behavior
				.drag();

			drag
				.on('dragstart', function (d) {
					d3.event.sourceEvent.stopPropagation();
					dragging = true;
					if (force.alpha()) {
						force.stop();
						paused = true;
					}
				});

			drag
				.on('drag', function (d) {
					d.px += d3.event.dx;
					d.py += d3.event.dy;
					d.x += d3.event.dx;
					d.y += d3.event.dy;
					tick();
				});

			drag
				.on('dragend', function (d) {
					if (d3.event.sourceEvent.shiftKey) {
						d.fixed = !d.fixed;
					}
					tick();
					dragging = false;
					force.resume();
					paused = false;
				});

			node
				.call(drag);

			force
				.nodes(nodes)
				.links(links)
				.on('tick', tick)
				.start();

			function zoomed() {
				container.attr('transform', 'translate(' + d3.event.translate +
					')scale(' + d3.event.scale + ')');
			}

			function format_tooltip (d) {
				var html = d.taskid + ' (' + d.status + ')<br />';
				html += d.name + '<br />';
				if (d.author) {
					var author = people[d.author];
					html +=
						mw.message( 'phabtaskgraph-author-field-label' ).text() +
 						': ' + author.username;
					if (author.realname) {
						 html += ' (' + author.realname + ')';
					}
					html += '<br />';
				}
				if (d.owner) {
					var owner = people[d.owner];
					html +=
						mw.message( 'phabtaskgraph-owner-field-label' ).text() +
						': ' + owner.username;
					if (owner.realname) {
					 html += ' (' + owner.realname + ')';
					}
					html += '<br />';
				}
				Object.keys(d.projects).forEach( function (key) {
					var color = projects[key].color;
					if (color == 'disabled') {
						color = 'grey';
					}
					html += '<span style="color:' + color + ';">';
					html += d.projects[key];
					html += '</span>';
					html += '<br />';
				});
				return html;
			}

			function tick() {
				link
					.attr('x1', function (d) {
						return d.source.x;
					})
					.attr('y1', function (d) {
						return d.source.y;
					})
					.attr('x2', function (d) {
						return d.target.x;
					})
					.attr('y2', function (d) {
						return d.target.y;
					});
				node
					.attr('transform', function (d) {
						return 'translate(' + d.x + ',' + d.y + ')';
					});
			}

			function neighboring(a, b) {
				return a.index == b.index || linkedByIndex[a.index + ',' + b.index];
			}

			function highlightConnected(d) {
				if (selectedNode != d.index) {
					node.style('opacity', function (o) {
						return neighboring(d, o) | neighboring(o, d) ? 1 : 0.1;
					});
					link.style('opacity', function (o) {
						return d.index==o.source.index | d.index==o.target.index ? 1 : 0.1;
					});
					selectedNode = d.index;
				} else {
					node.style('opacity', 1);
					link.style('opacity', 1);
					selectedNode = -1;
				}
			}

			function clearHighlight() {
				svg
					.selectAll('.phabtaskgraph-link')
					.style('opacity', 1);
				svg
					.selectAll('.phabtaskgraph-node')
					.style('opacity', 1);
				selectedNode = -1;
			}
		}
	};
}( mediaWiki, jQuery ) );

window.PhabTaskGraphController = phabtaskgraph_controller;

( function ( mw, $ ) {
	$( document )
		.ready( function () {
			if ( mw.config.exists( 'PhabTaskGraphConfig' ) ) {
				window.PhabTaskGraphController.initialize();
			}
		} );
}( mediaWiki, jQuery ) );
