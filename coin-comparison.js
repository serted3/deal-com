jQuery(document).ready(function($) {
    let debounceTimer;
    function extractWeight(weightString) {
        var match = weightString.match(/(\d+(?:\.\d+)?)/);
        return match ? parseFloat(match[1]) : 0;
    }
    
    function initializeFilters() {
    ['weight', 'sell_price', 'buy_price', 'spread'].forEach(function(key) {
            const minSlider = $('#' + key + '-slider-min');
            const maxSlider = $('#' + key + '-slider-max');
            const minInput = $('#' + key + '-min');
            const maxInput = $('#' + key + '-max');

            let min = parseFloat(coinComparisonData[key].min);
            let max = parseFloat(coinComparisonData[key].max);
            const step = 0.01;

            min = Math.floor(min * 100) / 100;
            max = Math.ceil(max * 100) / 100;

            minSlider.attr('min', min).attr('max', max).attr('step', step).val(min);
            maxSlider.attr('min', min).attr('max', max).attr('step', step).val(max);
            minInput.val(min.toFixed(2));
            maxInput.val(max.toFixed(2));

        function updateSlider(slider, input, value) {
            
            value = Math.round(value * 100) / 100;
            value = Math.min(Math.max(value, min), max);
            slider.val(value);
            input.val(value.toFixed(2));
        }

        minSlider.on('input', function() {
            const minVal = parseFloat($(this).val());
            const maxVal = parseFloat(maxSlider.val());
            if (minVal > maxVal) {
                updateSlider(maxSlider, maxInput, minVal);
            }
            updateSlider(minSlider, minInput, minVal);
            debouncedApplyFilters();
        });

        maxSlider.on('input', function() {
            const maxVal = parseFloat($(this).val());
            const minVal = parseFloat(minSlider.val());
            if (maxVal < minVal) {
                updateSlider(minSlider, minInput, maxVal);
            }
            updateSlider(maxSlider, maxInput, maxVal);
            debouncedApplyFilters();
        });

        minInput.on('change', function() {
            const minVal = parseFloat($(this).val());
            updateSlider(minSlider, minInput, minVal);
            debouncedApplyFilters();
        });

        maxInput.on('change', function() {
            const maxVal = parseFloat($(this).val());
            updateSlider(maxSlider, maxInput, maxVal);
            debouncedApplyFilters();
        });
    });
         

    }

    function getFilters() {
        return {
            name: $('#name-filter').val(),
            weight: {
                min: extractWeight($('#weight-min').val()),
                max: extractWeight($('#weight-max').val())
            },
            sell_price: {
                min: $('#sell_price-min').val(),
                max: $('#sell_price-max').val()
            },
            buy_price: {
                min: $('#buy_price-min').val(),
                max: $('#buy_price-max').val()
            },
            spread: {
                min: $('#spread-min').val(),
                max: $('#spread-max').val()
            },
            sellers: $('input[name="seller"]:checked').map(function() {
                return this.value;
            }).get(),
            availability: $('input[name="availability"]:checked').map(function() {
                return parseInt(this.value);
            }).get()
        };
    }

    function applyFilters() {
        var filters = {};

        coinComparisonColumns.forEach(function(column) {
            switch (column.column_name) {
                case 'name':
                    var nameFilter = $('#name-filter').val();
                    if (nameFilter) {
                        filters.name = nameFilter;
                    }
                    break;
                case 'weight':
                    var min = extractWeight($('#weight-min').val());
                    var max = extractWeight($('#weight-max').val());
                    if (min !== 0 || max !== 0) {
                        filters.weight = {
                            min: min,
                            max: max
                        };
                    }
                    break;
                case 'sell_price':
                case 'buy_price':
                case 'spread':
                    var min = parseFloat($('#' + column.column_name + '-min').val());
                    var max = parseFloat($('#' + column.column_name + '-max').val());
                    if (!isNaN(min) && !isNaN(max)) {
                        filters[column.column_name] = {
                            min: min,
                            max: max
                        };
                    }
                    break;
                case 'seller':
                    var selectedSellers = $('input[name="seller"]:checked').map(function() {
                        return this.value;
                    }).get();
                    if (selectedSellers.length > 0) {
                        filters.sellers = selectedSellers;
                    }
                    break;
                case 'availability':
                    var selectedAvailability = $('input[name="availability"]:checked').map(function() {
                        return parseInt(this.value);
                    }).get();
                    if (selectedAvailability.length > 0) {
                        filters.availability = selectedAvailability;
                    }
                    break;
            }
        });

        
        $.ajax({
            url: coin_comparison_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'coin_comparison_filter',
                filters: filters,
                security: coin_comparison_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('.coin-table tbody').html(response.data);
                }
            }
        });
    }

    initializeFilters();

    
    coinComparisonColumns.forEach(function(column) {
        if (['weight', 'sell_price', 'buy_price', 'spread'].includes(column.column_name)) {
            var minVal, maxVal;
            
            if (column.column_name === 'weight') {
                minVal = extractWeight(coinComparisonData[column.column_name].min.toString());
                maxVal = extractWeight(coinComparisonData[column.column_name].max.toString());
            } else {
                minVal = parseFloat(coinComparisonData[column.column_name].min);
                maxVal = parseFloat(coinComparisonData[column.column_name].max);
            }
            
            $("#" + column.column_name + "-slider-min, #" + column.column_name + "-slider-max").slider({
                range: true,
                min: minVal,
                max: maxVal,
                values: [minVal, maxVal],
                slide: function(event, ui) {
                    $("#" + column.column_name + "-min").val(ui.values[0].toFixed(2));
                    $("#" + column.column_name + "-max").val(ui.values[1].toFixed(2));
                },
                stop: debouncedApplyFilters
            });

            
            $("#" + column.column_name + "-min").val(minVal.toFixed(2));
            $("#" + column.column_name + "-max").val(maxVal.toFixed(2));
        }
    });

    
     


    function debouncedApplyFilters() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(applyFilters, 300);
    }

    function initializeSorting() {
    $('#coin-table').on('click', 'th[data-column]', function() {
        const table = $(this).parents('table').eq(0);
        const rows = table.find('tr:gt(0)').toArray().sort(comparer($(this).index()));
        this.asc = !this.asc;
        if (!this.asc) {
            rows.reverse();
        }
        for (let i = 0; i < rows.length; i++) {
            table.append(rows[i]);
        }
        
        $(this).siblings().removeClass('asc desc');
        $(this).removeClass('asc desc').addClass(this.asc ? 'asc' : 'desc');
    });
}

function applySorting() {
    const sortedColumn = $('#coin-table th[data-column].asc, #coin-table th[data-column].desc').first();
    if (sortedColumn.length) {
        sortedColumn.trigger('click');
        sortedColumn.trigger('click');
    }
}

function comparer(index) {
    return function(a, b) {
        const valA = getCellValue(a, index), valB = getCellValue(b, index);
        return $.isNumeric(valA) && $.isNumeric(valB) ? valA - valB : valA.toString().localeCompare(valB);
    }
}

function getCellValue(row, index) {
    return $(row).children('td').eq(index).text();
}

initializeSorting();


    $('.filters-container input').on('change', debouncedApplyFilters);
$('.filter-button').on('click', function() {
    $('.filters-container').slideToggle(); 
});

     


});