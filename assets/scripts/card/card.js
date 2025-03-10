import anime from './lib/anime.es.js';
import {round, clamp, adjust} from './mathHelper.js';

const elements = document.querySelectorAll('.card');

elements.forEach((element) => {
    let animations = [];

    element.addEventListener('pointermove', (event) => {
        const {pointerX, pointerY} = getEventPosition(event);

        computePositions(element, animations, pointerX, pointerY);
    });

    element.addEventListener("mouseout", (event) => {
        const {pointerX, pointerY} = getEventPosition(event);

        resetElement(element, animations, pointerX, pointerY);
    });
});

function computePositions(element, animations, pointerX, pointerY){
    const {percent, center} = getPositions(element, pointerX, pointerY);

    let background = {
        x: adjust(percent.x, 0, 100, 37, 63),
        y: adjust(percent.y, 0, 100, 33, 67),
    },
    rotate = {
        // /2 to make the rotation smoother
        x: round(-(center.x / 3.5))/2,
        y: round(center.y / 2)/2,
    }, 
    glare = {
        x: round(percent.x),
        y: round(percent.y),
        o: 1,
    }

    updateCard(element, animations, background, rotate, glare);
}

const getEventPosition = (event) => {
    let pointerX = event.clientX;
    let pointerY = event.clientY;

    if (event.type === "touchmove") {
        pointerX = event.touches[0].clientX;
        pointerY = event.touches[0].clientY;
    }

    return {
        pointerX: pointerX,
        pointerY: pointerY,
    };
}

function resetElement(element, animations, pointerX, pointerY) {
    animations[element.id] = anime({
        targets: element.querySelector('.card__rotator'),
        rotateX: {
            value: 0,
            easing: 'easeOutBack',
            delay: 100,
            endDelay: 100,
            duration: 800,
        },
        rotateY: {
            value: 0,
            easing: 'easeOutBack',
            delay: 100,
            endDelay: 100,
            duration: 800,
        },
        // Utilisation d'une fonction de mise à jour à chaque étape de l'animation
        update: function(anim) {
            const progress = anim.progress; // Valeur entre 0 et 100

            const {percent, center} = getPositions(element, pointerX, pointerY);

            // diff computation
            const diffX = 50 - percent.x;
            const diffY = 50 - percent.y;
            const newX = percent.x + (diffX * progress / 100);
            const newY = percent.y + (diffY * progress / 100);

            updateCard(element,animations,{
                x: adjust(newX, 0, 100, 37, 63),
                y: adjust(newY, 0, 100, 33, 67),
            }, {
                x: round(-(center.x / 3.5)),
                y: round(center.y / 2),
            }, {
                x: round(newX),
                y: round(newY),
                o: 1 - (progress / 50),
            }, true);
        }
    });
}

const getPositions = (element, pointerX, pointerY) => {
    const rectangle = element.getBoundingClientRect(); // get element's current size/position

    // get absolute position inside the card
    const absolute = {
        x: pointerX - rectangle.left, // get mouse position from left
        y: pointerY - rectangle.top, // get mouse position from right
    };
    // compute the absolute position to a percentage value
    const percent = {
        x: clamp(round((100 / rectangle.width) * absolute.x)),
        y: clamp(round((100 / rectangle.height) * absolute.y)),
    };
    // compute the center of the card
    const center = {
        x: percent.x - 50,
        y: percent.y - 50,
    };

    return {absolute, percent, center};
}

const updateCard = (element, animations, background, rotate, glare, reset) => {
    if (typeof animations[element.id] === 'object' && !reset) {
        animations[element.id].pause();
    }

    const pointerFromCenter =
        clamp(Math.sqrt(
            (glare.y - 50) * (glare.y - 50) +
            (glare.x - 50) * (glare.x - 50)
        ) / 50, 0, 1);

    element.style.setProperty('--pointer-x', glare.x + '%');
    element.style.setProperty('--pointer-y', glare.y + '%');
    element.style.setProperty('--pointer-from-center', pointerFromCenter);
    element.style.setProperty('--pointer-from-top', glare.y / 100);
    element.style.setProperty('--pointer-from-left', glare.x / 100);
    element.style.setProperty('--card-opacity', glare.o);
    element.style.setProperty('--background-x', background.x + '%');
    element.style.setProperty('--background-y', background.y + '%');
    element.style.setProperty('--card-scale', '1');
    element.style.setProperty('--translate-x', '0px');
    element.style.setProperty('--translate-y', '0px');

    if(!reset){
        element.querySelector('.card__rotator').style.setProperty("transform", "rotateX(" + rotate.y + "deg) rotateY(" + rotate.x + "deg)");
    }
}
